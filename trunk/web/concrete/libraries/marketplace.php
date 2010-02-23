<?

defined('C5_EXECUTE') or die(_("Access Denied."));

class Marketplace {
	
	const E_INVALID_BASE_URL = 20;
	const E_MARKETPLACE_SUPPORT_MANUALLY_DISABLED = 21;

	protected $isConnected = false;
	protected $connectionError = false;
	
	public static function getInstance() {
		static $instance;
		if (!isset($instance)) {
			$m = __CLASS__;
			$instance = new $m;
		}
		return $instance;
	}
	
	public function __construct() {
		if (defined('ENABLE_MARKETPLACE_SUPPORT') && ENABLE_MARKETPLACE_SUPPORT == false) {
			$this->connectionError = Marketplace::E_MARKETPLACE_SUPPORT_MANUALLY_DISABLED;
			return;
		}

		$csToken = Config::get('MARKETPLACE_SITE_TOKEN');
		if ($csToken != '') {

			$fh = Loader::helper('file');
			$csiURL = urlencode(BASE_URL . DIR_REL);
			$url = MARKETPLACE_URL_CONNECT_VALIDATE."?csToken={$csToken}&csiURL=" . $csiURL;
			$r = $fh->getContents($url);
			if ($r == false) {
				$this->isConnected = true;
			} else {
				$this->isConnected = false;
				$this->connectionError = $r;
			}
		}		
	}
	
	public function isConnected() {
		return $this->isConnected;
	}
	
	public function hasConnectionError() {
		return $this->connectionError != false;
	}
	
	public function getConnectionError() {
		return $this->connectionError;
	}
	
	public function generateSiteToken() {
		$fh = Loader::helper('file');
		$token = $fh->getContents(MARKETPLACE_URL_CONNECT_TOKEN_NEW);
		return $token;	
	}

	public function getSiteToken() {
		$token = Config::get('MARKETPLACE_SITE_TOKEN');
		return $token;
	}
	
	public function getSitePageURL() {
		$token = Config::get('MARKETPLACE_SITE_URL_TOKEN');
		return MARKETPLACE_BASE_URL_SITE_PAGE . '/' . $token;
	}

	public static function downloadRemoteFile($file) {
		$fh = Loader::helper('file');
		$file .= '?csiURL=' . urlencode(BASE_URL . DIR_REL);
		$pkg = $fh->getContents($file);
		if (empty($pkg)) {
			return Package::E_PACKAGE_DOWNLOAD;
		}

		$file = time();
		// Use the same method as the Archive library to build a temporary file name.
		$tmpFile = $fh->getTemporaryDirectory() . '/' . $file . '.zip';
		$fp = fopen($tmpFile, "wb");
		if ($fp) {
			fwrite($fp, $pkg);
			fclose($fp);
		} else {
			return Package::E_PACKAGE_SAVE;
		}
		
		return $file;
	}
	
	public function outputMarketplaceFrame($width = '100%', $height = '530', $completeURL = false) {
		// if $mpID is passed, we are going to either
		// a. go to its purchase page
		// b. pass you through to the page AFTER connecting.
		if (!$this->isConnected()) {
			$url = MARKETPLACE_URL_CONNECT;
			if (!$completeURL) {
				$completeURL = BASE_URL . View::url('/dashboard/settings/marketplace', 'connect_complete');
			}
			$csReferrer = urlencode($completeURL);
			$csiURL = urlencode(BASE_URL . DIR_REL);
			if ($this->hasConnectionError()) {
				$csToken = $this->getSiteToken();
			} else {
				// new connection 
				$csToken = Marketplace::generateSiteToken();
			}
			$url = $url . '?ts=' . time() . '&csiURL=' . $csiURL . '&csToken=' . $csToken . '&csReferrer=' . $csReferrer . '&csName=' . htmlspecialchars(SITE, ENT_QUOTES, APP_CHARSET);
		}
		print '<iframe id="ccm-marketplace-frame-' . time() . '" frameborder="0" width="' . $width . '" height="' . $height . '" src="' . $url . '"></iframe>';
	}
	
	
	/** 
	 * Runs through all packages on the marketplace, sees if they're installed here, and updates the available version number for them
	 */
	public static function checkPackageUpdates() {
		Loader::model('system_notification');
		$items = Marketplace::getAvailableMarketplaceItems(false);
		foreach($items as $i) {
			$p = Package::getByHandle($i->getHandle());
			$p->updateAvailableVersionNumber($i->getVersion());
			SystemNotification::add(SystemNotification::SN_TYPE_ADDON_UPDATE, t('An updated version of %s is available.', $i->getName()), t('New Version: %s.', $i->getVersion()), '', View::url('/dashboard/install', 'update'), $i->getRemoteURL());
		}
	}

	public function getAvailableMarketplaceItems($filterInstalled=true) {
		Loader::model('marketplace_remote_item');
		if (!function_exists('mb_detect_encoding')) {
			return array();
		}
		
		if (!is_array($addons)) {
			$fh = Loader::helper('file'); 
			if (!$fh) return array();

			// Retrieve the URL contents 
			$csToken = Config::get('MARKETPLACE_SITE_TOKEN');
			$csiURL = urlencode(BASE_URL . DIR_REL);
			$url = MARKETPLACE_PURCHASES_LIST_WS."?csToken={$csToken}&csiURL=" . $csiURL . "&csiVersion=" . APP_VERSION;
			$xml = $fh->getContents($url);

			$addons=array();
			if( $xml || strlen($xml) ) {
				// Parse the returned XML file
				$enc = mb_detect_encoding($xml);
				$xml = mb_convert_encoding($xml, 'UTF-8', $enc); 
				
				try {
					libxml_use_internal_errors(true);
					$xmlObj = new SimpleXMLElement($xml);
					foreach($xmlObj->addon as $addon){
						$mi = new MarketplaceRemoteItem();
						$mi->loadFromXML($addon);
						$remoteCID = $mi->getRemoteCollectionID();
						if (!empty($remoteCID)) {
							$addons[$mi->getHandle()] = $mi;
						}
					}
				} catch (Exception $e) {}
			}

		}

		if ($filterInstalled && is_array($addons)) {
			Loader::model('package');
			$handles = Package::getInstalledHandles();
			if (is_array($handles)) {
				$adlist = array();
				foreach($addons as $key=>$ad) {
					if (!in_array($ad->getHandle(), $handles)) {
						$adlist[$key] = $ad;
					}
				}
				$addons = $adlist;
			}
		}

		return $addons;
	}

}

?>