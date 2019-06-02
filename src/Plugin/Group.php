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
}
