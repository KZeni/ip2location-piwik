<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\IP2Location;

use Piwik\Common;
use Piwik\Notification;
use Piwik\Notification\Manager as NotificationManager;
use Piwik\Piwik;
use Piwik\Plugins\LanguagesManager\LanguagesManager;
use Piwik\View;
use Piwik\Plugins\IP2Location\API as IP2LocationAPI;
use Piwik\Menu\MenuAdmin;
use Piwik\Menu\MenuTop;
use Piwik\Menu\MenuMain;

/**
 *
 */
class Controller extends \Piwik\Plugin\Controller
{

    public function index()
    {
		Piwik::checkUserIsSuperUser();

		$view = new View('@IP2Location/index');
		$view->language = LanguagesManager::getLanguageCodeForCurrentUser();

		$this->setBasicVariablesView($view);
		$view->currentAdminMenuName = MenuAdmin::getInstance()->getCurrentAdminMenuName();
		$view->adminMenu = MenuAdmin::getInstance()->getMenu();
		$view->topMenu = MenuTop::getInstance()->getMenu();
		$view->notifications = NotificationManager::getAllNotificationsToDisplay();
		$view->phpVersion = phpversion();
		$view->phpIsNewEnough = version_compare($view->phpVersion, '5.3.0', '>=');
		$view->assign('dbNotFound', false);
		$view->assign('dbOutDated', false);

		$dbPath = PIWIK_INCLUDE_PATH . '/plugins/IP2Location/data/';
		$dbFile = '';

		foreach(array('IP2LOCATION-LITE-DB1.BIN', 'IP2LOCATION-LITE-DB3.BIN' ,'IP2LOCATION-LITE-DB5.BIN', 'IP2LOCATION-LITE-DB9.BIN', 'IP2LOCATION-LITE-DB11.BIN') as $file){
			if(file_exists($dbPath . $file)){
				$dbFile = $dbPath . $file;
				break;
			}
		}

		if(!$dbFile) $view->assign('dbNotFound', true);

		if($dbFile){
			if(filemtime($dbFile) < strtotime('-2 months')) $view->assign('dbOutDated', true);
			else{
				$view->assign('fileName', $file);
				$view->assign('date', date('d M, Y', filemtime($dbFile)));
			}

			$ipAddress = trim(Common::getRequestVar('ipAddress', $_SERVER['REMOTE_ADDR']));
			$view->assign('ipAddress', $ipAddress);

			$view->assign('showResults', false);

			if(!empty($_POST)){
				$view->assign('showResults', true);

				$result = IP2LocationAPI::lookup($ipAddress, $dbFile);

				$view->assign('country', ($result['countryCode'] != '-') ? ($result['countryName'] . ' (' . $result['countryCode'] . ')') : '-');
				$view->assign('regionName', (!preg_match('/not supported/', $result['regionName'])) ? $result['regionName'] : '-');
				$view->assign('cityName', (!preg_match('/not supported/', $result['cityName'])) ? $result['cityName'] : '-');
				$view->assign('position', (!preg_match('/not supported/', $result['latitude']) && $result['latitude'] != '-') ? ($result['latitude'] . ', ' . $result['longitude']) : '-');
			}

		}

		echo $view->render();
    }
}
