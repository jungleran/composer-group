<?php
/**
 * @file
 * Provides a way to patch Composer packages after installation.
 */
namespace JungleRan\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvents;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvent;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;

class Group implements PluginInterface, EventSubscriberInterface, Capable {

  /**
   * @var Composer $composer
   */
  protected $composer;
  /**
   * @var IOInterface $io
   */
  protected $io;
  /**
   * @var EventDispatcher $eventDispatcher
   */
  protected $eventDispatcher;
  /**
   * @var ProcessExecutor $executor
   */
  protected $executor;
  /**
   * @var array $patches
   */
  protected $patches;
  /**
   * @var bool $patchesResolved
   */
  protected $patchesResolved;
  /**
   * @var PatchCollection $patchCollection
   */
  protected $patchCollection;
  /**
   * Apply plugin modifications to composer
   *
   * @param Composer $composer
   * @param IOInterface $io
   */
  public function activate(Composer $composer, IOInterface $io)
  {
    $this->composer = $composer;
    $this->io = $io;
    $this->eventDispatcher = $composer->getEventDispatcher();
    $this->executor = new ProcessExecutor($this->io);
    $this->patches = array();
    $this->installedPatches = array();
    $this->patchesResolved = false;
    $this->patchCollection = new PatchCollection();
    $this->configuration = [
      'exit-on-patch-failure' => [
        'type' => 'bool',
        'default' => true,
      ],
      'disable-patching' => [
        'type' => 'bool',
        'default' => false,
      ],
      'disable-resolvers' => [
        'type' => 'list',
        'default' => [],
      ],
      'patch-levels' => [
        'type' => 'list',
        'default' => ['-p1', '-p0', '-p2', '-p4']
      ],
      'patches-file' => [
        'type' => 'string',
        'default' => '',
      ]
    ];
    $this->configure($this->composer->getPackage()->getExtra(), 'composer-patches');
  }
  /**
   * Returns an array of event names this subscriber wants to listen to.
   */
  public static function getSubscribedEvents()
  {
    return array(
      //            ScriptEvents::PRE_INSTALL_CMD => array('checkPatches'),
      //            ScriptEvents::PRE_UPDATE_CMD => array('checkPatches'),
      PackageEvents::PRE_PACKAGE_INSTALL => array('resolvePatches'),
      PackageEvents::PRE_PACKAGE_UPDATE => array('resolvePatches'),
      // The following is a higher weight for compatibility with
      // https://github.com/AydinHassan/magento-core-composer-installer and
      // more generally for compatibility with any Composer plugin which
      // deploys downloaded packages to other locations. In the case that
      // you want those plugins to deploy patched files, those plugins have
      // to run *after* this plugin.
      // @see: https://github.com/cweagans/composer-patches/pull/153
      PackageEvents::POST_PACKAGE_INSTALL => array('postInstall', 10),
      PackageEvents::POST_PACKAGE_UPDATE => array('postInstall', 10),
    );
  }
  /**
   * Return a list of plugin capabilities.
   *
   * @return array
   */
  public function getCapabilities()
  {
    return [
      'cweagans\Composer\Capability\ResolverProvider' => 'cweagans\Composer\Capability\CoreResolverProvider',
    ];
  }
  /**
   * Gather a list of all patch resolvers from all enabled Composer plugins.
   *
   * @return ResolverBase[]
   *   A list of PatchResolvers to be run.
   */
  public function getPatchResolvers()
  {
    $resolvers = [];
    $plugin_manager = $this->composer->getPluginManager();
    foreach ($plugin_manager->getPluginCapabilities(
      'cweagans\Composer\Capability\ResolverProvider',
      ['composer' => $this->composer, 'io' => $this->io]
    ) as $capability) {
      /** @var ResolverProvider $capability */
      $newResolvers = $capability->getResolvers();
      if (!is_array($newResolvers)) {
        throw new \UnexpectedValueException(
          'Plugin capability ' . get_class($capability) . ' failed to return an array from getResolvers().'
        );
      }
      foreach ($newResolvers as $resolver) {
        if (!$resolver instanceof ResolverBase) {
          throw new \UnexpectedValueException(
            'Plugin capability ' . get_class($capability) . ' returned an invalid value.'
          );
        }
      }
      $resolvers = array_merge($resolvers, $newResolvers);
    }
    return $resolvers;
  }
  /**
   * Gather patches that need to be applied to the current set of packages.
   *
   * Note that this work is done unconditionally if this plugin is enabled,
   * even if patching is disabled in any way. The point where patches are applied
   * is where the work will be skipped. It's done this way to ensure that
   * patching can be disabled temporarily in a way that doesn't affect the
   * contents of composer.lock.
   *
   * @param PackageEvent $event
   *   The PackageEvent passed by Composer
   */
  public function resolvePatches(PackageEvent $event)
  {
    // No need to resolve patches more than once.
    if ($this->patchesResolved) {
      return;
    }
    // Let each resolver discover patches and add them to the PatchCollection.
    /** @var ResolverInterface $resolver */
    foreach ($this->getPatchResolvers() as $resolver) {
      if (!in_array(get_class($resolver), $this->getConfig('disable-resolvers'), true)) {
        $resolver->resolve($this->patchCollection, $event);
      } else {
        if ($this->io->isVerbose()) {
          $this->io->write('<info>  - Skipping resolver ' . get_class($resolver) . '</info>');
        }
      }
    }
    // Make sure we only do this once.
    $this->patchesResolved = true;
  }
  /**
   * Before running composer install,
   * @param Event $event
   */
  public function checkPatches(Event $event)
  {
    if (!$this->isPatchingEnabled()) {
      return;
    }
    try {
      $repositoryManager = $this->composer->getRepositoryManager();
      $localRepository = $repositoryManager->getLocalRepository();
      $installationManager = $this->composer->getInstallationManager();
      $packages = $localRepository->getPackages();
      $tmp_patches = $this->grabPatches();
      foreach ($packages as $package) {
        $extra = $package->getExtra();
        if (isset($extra['patches'])) {
          $this->installedPatches[$package->getName()] = $extra['patches'];
        }
        $patches = isset($extra['patches']) ? $extra['patches'] : array();
        $tmp_patches = Util::arrayMergeRecursiveDistinct($tmp_patches, $patches);
      }
      if ($tmp_patches === false) {
        $this->io->write('<info>No patches supplied.</info>');
        return;
      }
      // Remove packages for which the patch set has changed.
      foreach ($packages as $package) {
        if (!($package instanceof AliasPackage)) {
          $package_name = $package->getName();
          $extra = $package->getExtra();
          $has_patches = isset($tmp_patches[$package_name]);
          $has_applied_patches = isset($extra['patches_applied']);
          if (($has_patches && !$has_applied_patches)
            || (!$has_patches && $has_applied_patches)
            || ($has_patches && $has_applied_patches &&
              $tmp_patches[$package_name] !== $extra['patches_applied'])) {
            $uninstallOperation = new UninstallOperation(
              $package,
              'Removing package so it can be re-installed and re-patched.'
            );
            $this->io->write('<info>Removing package ' .
              $package_name .
              ' so that it can be re-installed and re-patched.</info>');
            $installationManager->uninstall($localRepository, $uninstallOperation);
          }
        }
      }
    } catch (\LogicException $e) {
      // If the Locker isn't available, then we don't need to do this.
      // It's the first time packages have been installed.
      return;
    }
  }
  /**
   * @param PackageEvent $event
   * @throws \Exception
   */
  public function postInstall(PackageEvent $event)
  {
    // Get the package object for the current operation.
    $operation = $event->getOperation();
    /** @var PackageInterface $package */
    $package = $this->getPackageFromOperation($operation);
    $package_name = $package->getName();
    if (empty($this->patchCollection->getPatchesForPackage($package_name))) {
      if ($this->io->isVerbose()) {
        $this->io->write('<info>No patches found for ' . $package_name . '.</info>');
      }
      return;
    }
    $this->io->write('  - Applying patches for <info>' . $package_name . '</info>');
    // Get the install path from the package object.
    $manager = $event->getComposer()->getInstallationManager();
    $install_path = $manager->getInstaller($package->getType())->getInstallPath($package);
    // Set up a downloader.
    $downloader = new RemoteFilesystem($this->io, $this->composer->getConfig());
    // Track applied patches in the package info in installed.json
    $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
    $localPackage = $localRepository->findPackage($package_name, $package->getVersion());
    $extra = $localPackage->getExtra();
    $extra['patches_applied'] = array();
    foreach ($this->patchCollection->getPatchesForPackage($package_name) as $patch) {
      /** @var Patch $patch */
      $this->io->write('    <info>' . $patch->url . '</info> (<comment>' . $patch->description . '</comment>)');
      try {
        $this->eventDispatcher->dispatch(
          null,
          new PatchEvent(PatchEvents::PRE_PATCH_APPLY, $package, $patch->url, $patch->description)
        );
        $this->getAndApplyPatch($downloader, $install_path, $patch->url);
        $this->eventDispatcher->dispatch(
          null,
          new PatchEvent(PatchEvents::POST_PATCH_APPLY, $package, $patch->url, $patch->description)
        );
        $extra['patches_applied'][$patch->description] = $patch->url;
      } catch (\Exception $e) {
        $this->io->write(
          '   <error>Could not apply patch! Skipping. The error was: ' .
          $e->getMessage() .
          '</error>'
        );
        if ($this->getConfig('exit-on-patch-failure')) {
          throw new \Exception("Cannot apply patch $patch->description ($patch->url)!");
        }
      }
    }
    //        $localPackage->setExtra($extra);
  }
  /**
   * Get a Package object from an OperationInterface object.
   *
   * @param OperationInterface $operation
   * @return PackageInterface
   * @throws \Exception
   */
  protected function getPackageFromOperation(OperationInterface $operation)
  {
    if ($operation instanceof InstallOperation) {
      $package = $operation->getPackage();
    } elseif ($operation instanceof UpdateOperation) {
      $package = $operation->getTargetPackage();
    } else {
      throw new \Exception('Unknown operation: ' . get_class($operation));
    }
    return $package;
  }
}
