<?php
/**
 * Joomla! Framework Website
 *
 * @copyright  Copyright (C) 2014 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace Joomla\FrameworkWebsite\Service;

use Joomla\Application as JoomlaApplication;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\{
	Container, ServiceProviderInterface
};
use Joomla\FrameworkWebsite\{
	CliApplication, Console, Helper, WebApplication
};
use Joomla\FrameworkWebsite\Command as AppCommands;
use Joomla\FrameworkWebsite\Controller\{
	Api\PackageControllerGet, Api\StatusControllerGet, HomepageController, PackageController, PageController, StatusController, WrongCmsController
};
use Joomla\FrameworkWebsite\Helper\PackagistHelper;
use Joomla\FrameworkWebsite\Model\{
	PackageModel, ReleaseModel
};
use Joomla\FrameworkWebsite\View\{
	Package\PackageHtmlView, Package\PackageJsonView, Status\StatusHtmlView, Status\StatusJsonView
};
use Joomla\Http\Http;
use Joomla\Input\{
	Cli, Input
};
use Joomla\Registry\Registry;
use Joomla\Renderer\{
	RendererInterface, TwigRenderer
};
use Joomla\Router\Router;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use TheIconic\Tracking\GoogleAnalytics\Analytics;

/**
 * Application service provider
 */
class ApplicationProvider implements ServiceProviderInterface
{
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container)
	{
		/*
		 * Application Classes
		 */

		$container->alias(CliApplication::class, JoomlaApplication\AbstractCliApplication::class)
			->share(JoomlaApplication\AbstractCliApplication::class, [$this, 'getCliApplicationClassService'], true);

		$container->alias(WebApplication::class, JoomlaApplication\AbstractWebApplication::class)
			->share(JoomlaApplication\AbstractWebApplication::class, [$this, 'getWebApplicationClassService'], true);

		/*
		 * Application Helpers and Dependencies
		 */

		$container->alias(Analytics::class, 'analytics')
			->share('analytics', [$this, 'getAnalyticsService'], true);

		$container->alias(Helper::class, 'application.helper')
			->share('application.helper', [$this, 'getApplicationHelperService'], true);

		$container->alias(PackagistHelper::class, 'application.helper.packagist')
			->share('application.helper.packagist', [$this, 'getApplicationHelperPackagistService'], true);

		$container->share('application.packages', [$this, 'getApplicationPackagesService'], true);

		$container->alias(Router::class, 'application.router')
			->share('application.router', [$this, 'getApplicationRouterService'], true);

		$container->share(Input::class, [$this, 'getInputClassService'], true);
		$container->share(Cli::class, [$this, 'getInputCliClassService'], true);

		$container->share(Console::class, [$this, 'getConsoleClassService'], true);

		$container->share(JoomlaApplication\Cli\Output\Processor\ColorProcessor::class, [$this, 'getColorProcessorClassService'], true);
		$container->share(JoomlaApplication\Cli\CliInput::class, [$this, 'getCliInputClassService'], true);

		$container->alias(JoomlaApplication\Cli\CliOutput::class, JoomlaApplication\Cli\Output\Stdout::class)
			->share(JoomlaApplication\Cli\Output\Stdout::class, [$this, 'getCliOutputClassService'], true);

		/*
		 * Console Commands
		 */

		$container->share(AppCommands\HelpCommand::class, [$this, 'getHelpCommandClassService'], true);
		$container->share(AppCommands\Package\SyncCommand::class, [$this, 'getPackageSyncCommandClassService'], true);
		$container->share(AppCommands\Packagist\DownloadsCommand::class, [$this, 'getPackagistDownloadsCommandClassService'], true);
		$container->share(AppCommands\Packagist\SyncCommand::class, [$this, 'getPackagistSyncCommandClassService'], true);
		$container->share(AppCommands\Router\CacheCommand::class, [$this, 'getRouterCacheCommandClassService'], true);
		$container->share(AppCommands\Twig\ResetCacheCommand::class, [$this, 'getTwigResetCacheCommandClassService'], true);
		$container->share(AppCommands\UpdateCommand::class, [$this, 'getUpdateCommandClassService'], true);

		/*
		 * MVC Layer
		 */

		// Controllers
		$container->alias(PackageControllerGet::class, 'controller.api.package')
			->share('controller.api.package', [$this, 'getControllerApiPackageService'], true);

		$container->alias(StatusControllerGet::class, 'controller.api.status')
			->share('controller.api.status', [$this, 'getControllerApiStatusService'], true);

		$container->alias(HomepageController::class, 'controller.homepage')
			->share('controller.homepage', [$this, 'getControllerHomepageService'], true);

		$container->alias(PackageController::class, 'controller.package')
			->share('controller.package', [$this, 'getControllerPackageService'], true);

		$container->alias(PageController::class, 'controller.page')
			->share('controller.page', [$this, 'getControllerPageService'], true);

		$container->alias(StatusController::class, 'controller.status')
			->share('controller.status', [$this, 'getControllerStatusService'], true);

		$container->alias(WrongCmsController::class, 'controller.wrong.cms')
			->share('controller.wrong.cms', [$this, 'getControllerWrongCmsService'], true);

		// Models
		$container->alias(PackageModel::class, 'model.package')
			->share('model.package', [$this, 'getModelPackageService'], true);

		$container->alias(ReleaseModel::class, 'model.release')
			->share('model.release', [$this, 'getModelReleaseService'], true);

		// Views
		$container->alias(PackageHtmlView::class, 'view.package.html')
			->share('view.package.html', [$this, 'getViewPackageHtmlService'], true);

		$container->alias(PackageJsonView::class, 'view.package.json')
			->share('view.package.json', [$this, 'getViewPackageJsonService'], true);

		$container->alias(StatusHtmlView::class, 'view.status.html')
			->share('view.status.html', [$this, 'getViewStatusHtmlService'], true);

		$container->alias(StatusJsonView::class, 'view.status.json')
			->share('view.status.json', [$this, 'getViewStatusJsonService'], true);
	}

	/**
	 * Get the Analytics class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Analytics
	 */
	public function getAnalyticsService(Container $container)
	{
		return new Analytics(true);
	}

	/**
	 * Get the `application.helper` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Helper
	 */
	public function getApplicationHelperService(Container $container) : Helper
	{
		$helper = new Helper;
		$helper->setPackages($container->get('application.packages'));

		return $helper;
	}

	/**
	 * Get the `application.helper.packagist` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PackagistHelper
	 */
	public function getApplicationHelperPackagistService(Container $container) : PackagistHelper
	{
		$helper = new PackagistHelper($container->get(Http::class), $container->get(CacheItemPoolInterface::class));
		$helper->setPackages($container->get('application.packages'));

		return $helper;
	}

	/**
	 * Get the `application.packages` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Registry
	 */
	public function getApplicationPackagesService(Container $container) : Registry
	{
		return (new Registry)->loadFile(JPATH_ROOT . '/packages.yml', 'YAML');
	}

	/**
	 * Get the `application.router.chained` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ChainedRouter
	 */
	public function getApplicationRouterChainedService(Container $container) : ChainedRouter
	{
		return new ChainedRouter(
			[
				$container->get('application.router'),
				$container->get('application.router.rest'),
			]
		);
	}

	/**
	 * Get the `application.router` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Router
	 */
	public function getApplicationRouterService(Container $container) : Router
	{
		// Check for a cached router and use it
		if (file_exists(JPATH_ROOT . '/cache/CompiledRouter.php'))
		{
			require_once JPATH_ROOT . '/cache/CompiledRouter.php';

			return new \CompiledRouter;
		}

		$router = new Router;

		/*
		 * CMS Admin Panels
		 */
		$router->get(
			'/administrator',
			WrongCmsController::class
		);

		$router->get(
			'/administrator/*',
			WrongCmsController::class
		);

		$router->get(
			'/wp-admin',
			WrongCmsController::class
		);

		$router->get(
			'/wp-admin/*',
			WrongCmsController::class
		);

		$router->get(
			'wp-login.php',
			WrongCmsController::class
		);

		/*
		 * Web routes
		 */
		$router->get(
			'/',
			HomepageController::class
		);

		$router->head(
			'/',
			HomepageController::class
		);

		$router->get(
			'/status',
			StatusController::class
		);

		$router->get(
			'/:view',
			PageController::class
		);

		$router->get(
			'/status/:package',
			PackageController::class
		);

		/*
		 * API routes
		 */
		$router->get(
			'/api/v1/packages',
			StatusControllerGet::class
		);

		$router->get(
			'/api/v1/packages/:package',
			PackageControllerGet::class
		);

		return $router;
	}

	/**
	 * Get the CLI application service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  CliApplication
	 */
	public function getCliApplicationClassService(Container $container) : CliApplication
	{
		$application = new CliApplication(
			$container->get(Cli::class),
			$container->get('config'),
			$container->get(JoomlaApplication\Cli\CliOutput::class),
			$container->get(JoomlaApplication\Cli\CliInput::class),
			$container->get(Console::class)
		);

		$application->setLogger($container->get(LoggerInterface::class));

		return $application;
	}

	/**
	 * Get the CliInput class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  JoomlaApplication\Cli\CliInput
	 */
	public function getCliInputClassService(Container $container) : JoomlaApplication\Cli\CliInput
	{
		return new JoomlaApplication\Cli\CliInput;
	}

	/**
	 * Get the CliOutput class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  JoomlaApplication\Cli\CliOutput
	 */
	public function getCliOutputClassService(Container $container) : JoomlaApplication\Cli\Output\Stdout
	{
		return new JoomlaApplication\Cli\Output\Stdout($container->get(JoomlaApplication\Cli\Output\Processor\ColorProcessor::class));
	}

	/**
	 * Get the ColorProcessor class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  JoomlaApplication\Cli\Output\Processor\ColorProcessor
	 */
	public function getColorProcessorClassService(Container $container) : JoomlaApplication\Cli\Output\Processor\ColorProcessor
	{
		$processor = new JoomlaApplication\Cli\Output\Processor\ColorProcessor;

		/** @var Cli $input */
		$input = $container->get(Cli::class);

		if ($input->getBool('nocolors', false))
		{
			$processor->noColors = true;
		}

		// Setup app colors (also required in "nocolors" mode - to strip them).
		$processor->addStyle('title', new JoomlaApplication\Cli\ColorStyle('yellow', '', ['bold']));

		return $processor;
	}

	/**
	 * Get the console service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Console
	 */
	public function getConsoleClassService(Container $container) : Console
	{
		$console = new Console;
		$console->setContainer($container);

		return $console;
	}

	/**
	 * Get the `controller.api.package` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PackageControllerGet
	 */
	public function getControllerApiPackageService(Container $container) : PackageControllerGet
	{
		$controller = new PackageControllerGet(
			$container->get(PackageJsonView::class),
			$container->get(Analytics::class),
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);

		$controller->setLogger($container->get(LoggerInterface::class));

		return $controller;
	}

	/**
	 * Get the `controller.api.status` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  StatusControllerGet
	 */
	public function getControllerApiStatusService(Container $container) : StatusControllerGet
	{
		$controller = new StatusControllerGet(
			$container->get(StatusJsonView::class),
			$container->get(Analytics::class),
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);

		$controller->setLogger($container->get(LoggerInterface::class));

		return $controller;
	}

	/**
	 * Get the `controller.homepage` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  HomepageController
	 */
	public function getControllerHomepageService(Container $container) : HomepageController
	{
		return new HomepageController(
			$container->get(RendererInterface::class),
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);
	}

	/**
	 * Get the `controller.package` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PackageController
	 */
	public function getControllerPackageService(Container $container) : PackageController
	{
		return new PackageController(
			$container->get(PackageHtmlView::class),
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);
	}

	/**
	 * Get the `controller.page` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PageController
	 */
	public function getControllerPageService(Container $container) : PageController
	{
		return new PageController(
			$container->get(RendererInterface::class),
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);
	}

	/**
	 * Get the `controller.status` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  StatusController
	 */
	public function getControllerStatusService(Container $container) : StatusController
	{
		return new StatusController(
			$container->get(StatusHtmlView::class),
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);
	}

	/**
	 * Get the `controller.wrong.cms` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  WrongCmsController
	 */
	public function getControllerWrongCmsService(Container $container) : WrongCmsController
	{
		return new WrongCmsController(
			$container->get(Input::class),
			$container->get(WebApplication::class)
		);
	}

	/**
	 * Get the HelpCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\HelpCommand
	 */
	public function getHelpCommandClassService(Container $container) : AppCommands\HelpCommand
	{
		return new AppCommands\HelpCommand(
			$container->get(Console::class),
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);
	}

	/**
	 * Get the Input class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Input
	 */
	public function getInputClassService(Container $container) : Input
	{
		return new Input($_REQUEST);
	}

	/**
	 * Get the Input\Cli class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Cli
	 */
	public function getInputCliClassService(Container $container) : Cli
	{
		return new Cli;
	}

	/**
	 * Get the `model.package` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PackageModel
	 */
	public function getModelPackageService(Container $container) : PackageModel
	{
		return new PackageModel($container->get(DatabaseDriver::class));
	}

	/**
	 * Get the `model.release` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ReleaseModel
	 */
	public function getModelReleaseService(Container $container) : ReleaseModel
	{
		return new ReleaseModel($container->get(DatabaseDriver::class));
	}

	/**
	 * Get the Package\SyncCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\Package\SyncCommand
	 */
	public function getPackageSyncCommandClassService(Container $container) : AppCommands\Package\SyncCommand
	{
		return new AppCommands\Package\SyncCommand(
			$container->get(Helper::class),
			$container->get(PackageModel::class),
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);
	}

	/**
	 * Get the Packagist\DownloadsCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\Packagist\DownloadsCommand
	 */
	public function getPackagistDownloadsCommandClassService(Container $container) : AppCommands\Packagist\DownloadsCommand
	{
		return new AppCommands\Packagist\DownloadsCommand(
			$container->get(PackagistHelper::class),
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);
	}

	/**
	 * Get the Packagist\SyncCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\Packagist\SyncCommand
	 */
	public function getPackagistSyncCommandClassService(Container $container) : AppCommands\Packagist\SyncCommand
	{
		return new AppCommands\Packagist\SyncCommand(
			$container->get(Http::class),
			$container->get(PackageModel::class),
			$container->get(ReleaseModel::class),
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);
	}

	/**
	 * Get the Router\CacheCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\Router\CacheCommand
	 */
	public function getRouterCacheCommandClassService(Container $container) : AppCommands\Router\CacheCommand
	{
		$command = new AppCommands\Router\CacheCommand(
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);

		$command->setContainer($container);

		return $command;
	}

	/**
	 * Get the Twig\ResetCacheCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\Twig\ResetCacheCommand
	 */
	public function getTwigResetCacheCommandClassService(Container $container) : AppCommands\Twig\ResetCacheCommand
	{
		return new AppCommands\Twig\ResetCacheCommand(
			$container->get(TwigRenderer::class),
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);
	}

	/**
	 * Get the UpdateCommand class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AppCommands\UpdateCommand
	 */
	public function getUpdateCommandClassService(Container $container) : AppCommands\UpdateCommand
	{
		return new AppCommands\UpdateCommand(
			$container->get(Console::class),
			$container->get(Input::class),
			$container->get(JoomlaApplication\AbstractApplication::class)
		);
	}

	/**
	 * Get the `view.package.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PackageHtmlView
	 */
	public function getViewPackageHtmlService(Container $container) : PackageHtmlView
	{
		$view = new PackageHtmlView(
			$container->get('model.package'),
			$container->get('model.release'),
			$container->get(Helper::class),
			$container->get('renderer')
		);

		$view->setLayout('package.twig');

		return $view;
	}

	/**
	 * Get the `view.package.json` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PackageJsonView
	 */
	public function getViewPackageJsonService(Container $container) : PackageJsonView
	{
		return new PackageJsonView(
			$container->get('model.package'),
			$container->get('model.release')
		);
	}

	/**
	 * Get the `view.status.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  StatusHtmlView
	 */
	public function getViewStatusHtmlService(Container $container) : StatusHtmlView
	{
		$view = new StatusHtmlView(
			$container->get('model.package'),
			$container->get('model.release'),
			$container->get(PackagistHelper::class),
			$container->get('renderer')
		);

		$view->setLayout('status.twig');

		return $view;
	}

	/**
	 * Get the `view.status.json` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  StatusJsonView
	 */
	public function getViewStatusJsonService(Container $container) : StatusJsonView
	{
		return new StatusJsonView(
			$container->get('model.package'),
			$container->get('model.release')
		);
	}

	/**
	 * Get the WebApplication class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  WebApplication
	 */
	public function getWebApplicationClassService(Container $container) : WebApplication
	{
		/** @var Registry $config */
		$config = $container->get('config');

		$application = new WebApplication($container->get(Input::class), $config);

		// Inject extra services
		$application->setContainer($container);
		$application->setLogger($container->get(LoggerInterface::class));
		$application->setRouter($container->get(Router::class));

		if ($config->get('debug', false) && $container->has('debug.bar'))
		{
			$application->setDebugBar($container->get('debug.bar'));
		}

		return $application;
	}
}
