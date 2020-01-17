<?php

namespace Piwik\Plugins\IP2Location\LocationProvider;

use Piwik\Http;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\UserCountry\LocationProvider;

class IP2Location extends LocationProvider
{
	const ID = 'ip2location';
	const TITLE = 'IP2Location';

	public function __construct()
	{
	}

	/**
	 * Returns information about this location provider.
	 *
	 * @return array
	 */
	public function getInfo()
	{
		$extraMessage = '';

		if (Option::get('IP2Location.LookupMode') == 'WS') {
			$extraMessage = '
				<strong>Lookup Mode: </strong>IP2Location Web Service<br/>
				<strong>API Key: </strong>' . Option::get('IP2Location.APIKey');
		} else {
			if ($this->getDatabasePath()) {
				$extraMessage = '
				<strong>Lookup Mode: </strong>IP2Location BIN Database<br/>
				<strong>Database File: </strong>' . basename($this->getDatabasePath());
			}
		}

		return [
			'id'          => self::ID,
			'title'       => self::TITLE,
			'order'       => 5,
			'description' => implode("\n", [
				'<p>',
				'This location provider uses <strong>IP2Location database</strong> to accurately detect the location of your visitors.',
				'It support both IPv4 and IPv6 address detection. In addition, you can choose to use either BIN database or web service for the geolocation lookup.',
				'</p>',
				'<p><ul>',
				'<li><a href="https://lite.ip2location.com/?r=piwik" rel="noreferrer"  target="_blank">Download free IP2Location LITE BIN database &raquo;</a></li>',
				'<li><a href="https://www.ip2location.com/?r=piwik" rel="noreferrer"  target="_blank">Download free IP2Location Commercial BIN database &raquo;</a></li>',
				'<li><a href="https://www.ip2location.com/web-service/ip2location/?r=piwik" rel="noreferrer"  target="_blank">Sign up for web service &raquo;</a></li>',
				'</ul></p>',
			]),
			'install_docs'  => 'For BIN database option, please upload IP2Location BIN database file into <strong>Piwik/misc</strong> folder.',
			'extra_message' => $extraMessage,
		];
	}

	/**
	 * Get a visitor's location based on their IP address.
	 *
	 * @param array $info must have an 'ip' field
	 *
	 * @return array
	 */
	public function getLocation($info)
	{
		$ip = $this->getIpFromInfo($info);

		$result = [];

		if (Option::get('IP2Location.LookupMode') == 'WS' && Option::get('IP2Location.APIKey')) {
			$response = Http::sendHttpRequest('https://api.ip2location.com/v2/?key=' . Option::get('IP2Location.APIKey') . '&ip=' . $ip . '&format=json&package=WS6', 30);

			if (($json = json_decode($response)) !== null) {
				if (!isset($json->response)) {
					$result[self::COUNTRY_CODE_KEY] = $json->country_code;
					$result[self::COUNTRY_NAME_KEY] = $json->country_name;
					$result[self::REGION_CODE_KEY] = $this->getRegionCode($json->country_code, $json->region_name);
					$result[self::REGION_NAME_KEY] = $json->region_name;
					$result[self::CITY_NAME_KEY] = $json->city_name;
					$result[self::LATITUDE_KEY] = $json->latitude;
					$result[self::LONGITUDE_KEY] = $json->longitude;
					$result[self::ISP_KEY] = $json->isp;
				}
			}
		} else {
			require_once PIWIK_INCLUDE_PATH . '/plugins/IP2Location/lib/IP2Location.php';

			$db = new \IP2Location\Database(self::getDatabasePath(), \IP2Location\Database::FILE_IO);
			$response = $db->lookup($ip, \IP2Location\Database::ALL);

			$result[self::COUNTRY_CODE_KEY] = $response['countryCode'];
			$result[self::COUNTRY_NAME_KEY] = $response['countryName'];

			if (strpos($response['regionName'], 'unavailable') === false) {
				$result[self::REGION_CODE_KEY] = $this->getRegionCode($response['countryCode'], $response['regionName']);
				$result[self::REGION_NAME_KEY] = $response['regionName'];
			}

			if (strpos($response['cityName'], 'unavailable') === false) {
				$result[self::CITY_NAME_KEY] = $response['cityName'];
			}

			if (strpos($response['latitude'], 'unavailable') === false) {
				$result[self::LATITUDE_KEY] = $response['latitude'];
				$result[self::LONGITUDE_KEY] = $response['longitude'];
			}

			if (strpos($response['isp'], 'unavailable') === false) {
				$result[self::ISP_KEY] = $response['isp'];
			}
		}

		$this->completeLocationResult($result);

		return $result;
	}

	/**
	 * Returns an array describing the types of location information this provider will
	 * return.
	 *
	 * @return array
	 */
	public function getSupportedLocationInfo()
	{
		$result = [];

		// Country & continent information always available
		$result[self::COUNTRY_CODE_KEY] = true;
		$result[self::COUNTRY_NAME_KEY] = true;
		$result[self::CONTINENT_CODE_KEY] = true;
		$result[self::CONTINENT_NAME_KEY] = true;

		require_once PIWIK_INCLUDE_PATH . '/plugins/IP2Location/lib/IP2Location.php';

		$db = new \IP2Location\Database(self::getDatabasePath(), \IP2Location\Database::FILE_IO);
		$response = $db->lookup('8.8.8.8', \IP2Location\Database::ALL);

		if (strpos($response['regionName'], 'unavailable') === false) {
			$result[self::REGION_CODE_KEY] = true;
			$result[self::REGION_NAME_KEY] = true;
		}

		if (strpos($response['cityName'], 'unavailable') === false) {
			$result[self::CITY_NAME_KEY] = true;
		}

		if (strpos($response['latitude'], 'unavailable') === false) {
			$result[self::LATITUDE_KEY] = true;
			$result[self::LONGITUDE_KEY] = true;
		}

		if (strpos($response['isp'], 'unavailable') === false) {
			$result[self::ISP_KEY] = true;
		}

		return $result;
	}

	/**
	 * Returns true if this location provider is available.
	 *
	 * @return bool
	 */
	public function isAvailable()
	{
		if (Option::get('IP2Location.APIKey') || self::getDatabasePath() !== false) {
			return true;
		}

		return false;
	}

	/**
	 * Returns true if this provider has been setup correctly, the error message if
	 * otherwise.
	 *
	 * @return bool|string
	 */
	public function isWorking()
	{
		if (!empty(Option::get('IP2Location.APIKey'))) {
			return true;
		}

		require_once PIWIK_INCLUDE_PATH . '/plugins/IP2Location/lib/IP2Location.php';

		if (!file_exists(self::getDatabasePath())) {
			return 'The IP2Location BIN database file is not found.';
		}

		$db = new \IP2Location\Database(self::getDatabasePath(), \IP2Location\Database::FILE_IO);
		$response = $db->lookup('8.8.8.8', \IP2Location\Database::ALL);

		if (!isset($response['countryCode'])) {
			return 'The IP2Location database file is corrupted.';
		}

		if (strpos($response['countryCode'], 'Invalid') !== false) {
			return 'The IP2Location database file is corrupted.';
		}

		return true;
	}

	/**
	 * Returns full path for a IP2Location database file.
	 *
	 * @return string
	 */
	public static function getDatabasePath()
	{
		// Scan misc directory for any file with .BIN extension
		$files = scandir(PIWIK_INCLUDE_PATH . '/misc');

		if (empty($files)) {
			return false;
		}

		foreach ($files as $file) {
			if (strtoupper(substr($file, -4)) == '.BIN') {
				return PIWIK_INCLUDE_PATH . '/misc/' . $file;
			}
		}

		return false;
	}

	/**
	 * Get region code by country code and region name.
	 *
	 * @param $countryCode
	 * @param $regionName
	 *
	 * @return false|string
	 */
	private function getRegionCode($countryCode, $regionName)
	{
		$regions = [
			'AD' => [
				'02' => 'CANILLO', '03' => 'ENCAMP', '04' => 'LA MASSANA', '05' => 'ORDINO', '06' => 'SANT JULIA DE LORIA', '07' => 'ANDORRA LA VELLA', '08' => 'ESCALDES-ENGORDANY',
			],
			'AE' => [
				'01' => 'ABU DHABI', '02' => 'AJMAN', '03' => 'DUBAI', '04' => 'FUJAIRAH', '05' => 'RAS AL KHAIMAH', '06' => 'SHARJAH', '07' => 'UMM AL QUWAIN',
			],
			'AF' => [
				'01' => 'BADAKHSHAN', '02' => 'BADGHIS', '03' => 'BAGHLAN', '05' => 'BAMIAN', '06' => 'FARAH', '07' => 'FARYAB', '08' => 'GHAZNI', '09' => 'GHOWR', '10' => 'HELMAND', '11' => 'HERAT', '13' => 'KABOL', '14' => 'KAPISA', '17' => 'LOWGAR', '18' => 'NANGARHAR', '19' => 'NIMRUZ', '23' => 'KANDAHAR', '24' => 'KONDOZ', '26' => 'TAKHAR', '27' => 'VARDAK', '28' => 'ZABOL', '29' => 'PAKTIKA', '30' => 'BALKH', '31' => 'JOWZJAN', '32' => 'SAMANGAN', '33' => 'SAR-E POL', '34' => 'KONAR', '35' => 'LAGHMAN', '36' => 'PAKTIA', '37' => 'KHOWST', '38' => 'NURESTAN', '39' => 'ORUZGAN', '40' => 'PARVAN', '41' => 'DAYKONDI', '42' => 'PANJSHIR',
			],
			'AG' => [
				'00' => 'ANTIGUA AND BARBUDA', '04' => 'SAINT JOHN', '05' => 'SAINT MARY', '06' => 'SAINT PAUL',
			],
			'AI' => [
				'00' => 'ANGUILLA',
			],
			'AL' => [
				'40' => 'BERAT', '41' => 'DIBER', '42' => 'DURRES', '43' => 'ELBASAN', '44' => 'FIER', '45' => 'GJIROKASTER', '46' => 'KORCE', '47' => 'KUKES', '48' => 'LEZHE', '49' => 'SHKODER', '50' => 'TIRANE', '51' => 'VLORE',
			],
			'AM' => [
				'01' => 'ARAGATSOTN', '02' => 'ARARAT', '03' => 'ARMAVIR', '04' => 'GEGHARK\'UNIK\'', '05' => 'KOTAYK\'', '06' => 'LORRI', '07' => 'SHIRAK', '08' => 'SYUNIK\'', '09' => 'TAVUSH', '10' => 'VAYOTS\' DZOR', '11' => 'YEREVAN',
			],
			'AO' => [
				'01' => 'BENGUELA', '02' => 'BIE', '03' => 'CABINDA', '04' => 'CUANDO CUBANGO', '05' => 'CUANZA NORTE', '06' => 'CUANZA SUL', '07' => 'CUNENE', '08' => 'HUAMBO', '09' => 'HUILA', '12' => 'MALANJE', '13' => 'NAMIBE', '14' => 'MOXICO', '15' => 'UIGE', '16' => 'ZAIRE', '17' => 'LUNDA NORTE', '18' => 'LUNDA SUL', '19' => 'BENGO', '20' => 'LUANDA',
			],
			'AQ' => [
				'00' => 'ANTARCTICA',
			],
			'AR' => [
				'01' => 'BUENOS AIRES', '02' => 'CATAMARCA', '03' => 'CHACO', '04' => 'CHUBUT', '05' => 'CORDOBA', '06' => 'CORRIENTES', '07' => 'DISTRITO FEDERAL', '08' => 'ENTRE RIOS', '09' => 'FORMOSA', '10' => 'JUJUY', '11' => 'LA PAMPA', '12' => 'LA RIOJA', '13' => 'MENDOZA', '14' => 'MISIONES', '15' => 'NEUQUEN', '16' => 'RIO NEGRO', '17' => 'SALTA', '18' => 'SAN JUAN', '19' => 'SAN LUIS', '20' => 'SANTA CRUZ', '21' => 'SANTA FE', '22' => 'SANTIAGO DEL ESTERO', '23' => 'TIERRA DEL FUEGO', '24' => 'TUCUMAN',
			],
			'AS' => [
				'10' => 'EASTERN DISTRICT', '50' => 'WESTERN DISTRICT',
			],
			'AT' => [
				'01' => 'BURGENLAND', '02' => 'KARNTEN', '03' => 'NIEDEROSTERREICH', '04' => 'OBEROSTERREICH', '05' => 'SALZBURG', '06' => 'STEIERMARK', '07' => 'TIROL', '08' => 'VORARLBERG', '09' => 'WIEN',
			],
			'AU' => [
				'01' => 'AUSTRALIAN CAPITAL TERRITORY', '02' => 'NEW SOUTH WALES', '03' => 'NORTHERN TERRITORY', '04' => 'QUEENSLAND', '05' => 'SOUTH AUSTRALIA', '06' => 'TASMANIA', '07' => 'VICTORIA', '08' => 'WESTERN AUSTRALIA',
			],
			'AW' => [
				'00' => 'ARUBA (GENERAL)',
			],
			'AX' => [
				'01' => 'ECKEROE', '02' => 'FINSTROEM', '03' => 'HAMMARLAND', '04' => 'JOMALA', '05' => 'LEMLAND', '06' => 'MARIEHAMN', '07' => 'SALTVIK', '08' => 'SUND',
			],
			'AZ' => [
				'01' => 'ABSERON', '02' => 'AGCABADI', '03' => 'AGDAM', '04' => 'AGDAS', '05' => 'AGSTAFA', '06' => 'AGSU', '07' => 'ALI BAYRAMLI', '08' => 'ASTARA', '09' => 'BAKI', '10' => 'BALAKAN', '11' => 'BARDA', '12' => 'BEYLAQAN', '13' => 'BILASUVAR', '14' => 'CABRAYIL', '15' => 'CALILABAD', '16' => 'DASKASAN', '18' => 'FUZULI', '19' => 'GADABAY', '20' => 'GANCA', '21' => 'GORANBOY', '22' => 'GOYCAY', '23' => 'HACIQABUL', '24' => 'IMISLI', '25' => 'ISMAYILLI', '26' => 'KALBACAR', '28' => 'LACIN', '29' => 'LANKARAN', '30' => 'LANKARAN', '31' => 'LERIK', '32' => 'MASALLI', '33' => 'MINGACEVIR', '34' => 'NAFTALAN', '35' => 'NAXCIVAN', '36' => 'NEFTCALA', '37' => 'OGUZ', '38' => 'QABALA', '39' => 'QAX', '40' => 'QAZAX', '41' => 'QOBUSTAN', '42' => 'QUBA', '43' => 'QUBADLI', '44' => 'QUSAR', '45' => 'SAATLI', '46' => 'SABIRABAD', '47' => 'SAKI', '48' => 'SAKI', '49' => 'SALYAN', '50' => 'SAMAXI', '51' => 'SAMKIR', '52' => 'SAMUX', '54' => 'SUMQAYIT', '56' => 'SUSA', '57' => 'TARTAR', '58' => 'TOVUZ', '59' => 'UCAR', '60' => 'XACMAZ', '61' => 'XANKANDI', '62' => 'XANLAR', '63' => 'XIZI', '64' => 'XOCALI', '65' => 'XOCAVAND', '66' => 'YARDIMLI', '67' => 'YEVLAX', '68' => 'YEVLAX', '69' => 'ZANGILAN', '70' => 'ZAQATALA', '71' => 'ZARDAB',
			],
			'BA' => [
				'01' => 'FEDERATION OF BOSNIA AND HERZEGOVINA', '02' => 'REPUBLIKA SRPSKA',
			],
			'BB' => [
				'01' => 'CHRIST CHURCH', '04' => 'SAINT JAMES', '06' => 'SAINT JOSEPH', '08' => 'SAINT MICHAEL', '09' => 'SAINT PETER',
			],
			'BD' => [
				'81' => 'DHAKA', '82' => 'KHULNA', '83' => 'RAJSHAHI', '84' => 'CHITTAGONG', '85' => 'BARISAL', '86' => 'SYLHET', '87' => 'RANGPUR',
			],
			'BE' => [
				'01' => 'ANTWERPEN', '03' => 'HAINAUT', '04' => 'LIEGE', '05' => 'LIMBURG', '06' => 'LUXEMBOURG', '07' => 'NAMUR', '08' => 'OOST-VLAANDEREN', '09' => 'WEST-VLAANDEREN', '10' => 'BRABANT WALLON', '11' => 'BRUSSELS HOOFDSTEDELIJK GEWEST', '12' => 'VLAAMS-BRABANT',
			],
			'BF' => [
				'15' => 'BAM', '19' => 'BOULKIEMDE', '20' => 'GANZOURGOU', '21' => 'GNAGNA', '28' => 'KOURITENGA', '33' => 'OUDALAN', '34' => 'PASSORE', '36' => 'SANGUIE', '40' => 'SOUM', '42' => 'TAPOA', '44' => 'ZOUNDWEOGO', '45' => 'BALE', '46' => 'BANWA', '47' => 'BAZEGA', '48' => 'BOUGOURIBA', '49' => 'BOULGOU', '50' => 'GOURMA', '51' => 'HOUET', '52' => 'IOBA', '53' => 'KADIOGO', '54' => 'KENEDOUGOU', '55' => 'KOMOE', '56' => 'KOMONDJARI', '57' => 'KOMPIENGA', '58' => 'KOSSI', '59' => 'KOULPELOGO', '60' => 'KOURWEOGO', '61' => 'LERABA', '62' => 'LOROUM', '63' => 'MOUHOUN', '64' => 'NAMENTENGA', '65' => 'NAOURI', '66' => 'NAYALA', '67' => 'NOUMBIEL', '68' => 'OUBRITENGA', '69' => 'PONI', '70' => 'SANMATENGA', '71' => 'SENO', '72' => 'SISSILI', '73' => 'SOUROU', '74' => 'TUY', '75' => 'YAGHA', '76' => 'YATENGA', '77' => 'ZIRO', '78' => 'ZONDOMA',
			],
			'BG' => [
				'38' => 'BLAGOEVGRAD', '39' => 'BURGAS', '40' => 'DOBRICH', '41' => 'GABROVO', '42' => 'GRAD SOFIYA', '43' => 'KHASKOVO', '44' => 'KURDZHALI', '45' => 'KYUSTENDIL', '46' => 'LOVECH', '47' => 'MONTANA', '48' => 'PAZARDZHIK', '49' => 'PERNIK', '50' => 'PLEVEN', '51' => 'PLOVDIV', '52' => 'RAZGRAD', '53' => 'RUSE', '54' => 'SHUMEN', '55' => 'SILISTRA', '56' => 'SLIVEN', '57' => 'SMOLYAN', '58' => 'SOFIYA', '59' => 'STARA ZAGORA', '60' => 'TURGOVISHTE', '61' => 'VARNA', '62' => 'VELIKO TURNOVO', '63' => 'VIDIN', '64' => 'VRATSA', '65' => 'YAMBOL',
			],
			'BH' => [
				'15' => 'AL MUHARRAQ', '16' => 'AL ASIMAH', '18' => 'ASH SHAMALIYAH', '19' => 'AL WUSTA',
			],
			'BI' => [
				'09' => 'BUBANZA', '10' => 'BURURI', '11' => 'CANKUZO', '12' => 'CIBITOKE', '13' => 'GITEGA', '14' => 'KARUZI', '15' => 'KAYANZA', '16' => 'KIRUNDO', '17' => 'MAKAMBA', '18' => 'MUYINGA', '19' => 'NGOZI', '20' => 'RUTANA', '21' => 'RUYIGI', '22' => 'MURAMVYA', '23' => 'MWARO', '24' => 'BUJUMBURA MAIRIE',
			],
			'BJ' => [
				'07' => 'ALIBORI', '08' => 'ATAKORA', '09' => 'ATLANTIQUE', '10' => 'BORGOU', '11' => 'COLLINES', '12' => 'KOUFFO', '13' => 'DONGA', '14' => 'LITTORAL', '15' => 'MONO', '16' => 'OUEME', '17' => 'PLATEAU', '18' => 'ZOU',
			],
			'BL' => [
				'00' => 'SAINT BARTHELEMY',
			],
			'BM' => [
				'03' => 'HAMILTON', '06' => 'SAINT GEORGE',
			],
			'BN' => [
				'08' => 'BELAIT', '09' => 'BRUNEI AND MUARA', '10' => 'TEMBURONG', '15' => 'TUTONG',
			],
			'BO' => [
				'01' => 'CHUQUISACA', '02' => 'COCHABAMBA', '03' => 'EL BENI', '04' => 'LA PAZ', '05' => 'ORURO', '06' => 'PANDO', '07' => 'POTOSI', '08' => 'SANTA CRUZ', '09' => 'TARIJA',
			],
			'BQ' => [
				'BO' => 'BONAIRE', 'SB' => 'SABA', 'SE' => 'SINT EUSTATIUS',
			],
			'BR' => [
				'01' => 'ACRE', '02' => 'ALAGOAS', '03' => 'AMAPA', '04' => 'AMAZONAS', '05' => 'BAHIA', '06' => 'CEARA', '07' => 'DISTRITO FEDERAL', '08' => 'ESPIRITO SANTO', '11' => 'MATO GROSSO DO SUL', '13' => 'MARANHAO', '14' => 'MATO GROSSO', '15' => 'MINAS GERAIS', '16' => 'PARA', '17' => 'PARAIBA', '18' => 'PARANA', '20' => 'PIAUI', '21' => 'RIO DE JANEIRO', '22' => 'RIO GRANDE DO NORTE', '23' => 'RIO GRANDE DO SUL', '24' => 'RONDONIA', '25' => 'RORAIMA', '26' => 'SANTA CATARINA', '27' => 'SAO PAULO', '28' => 'SERGIPE', '29' => 'GOIAS', '30' => 'PERNAMBUCO', '31' => 'TOCANTINS',
			],
			'BS' => [
				'15' => 'LONG ISLAND', '22' => 'HARBOUR ISLAND', '23' => 'NEW PROVIDENCE', '25' => 'FREEPORT', '26' => 'FRESH CREEK', '29' => 'HIGH ROCK', '31' => 'MARSH HARBOUR', '33' => 'ROCK SOUND',
			],
			'BT' => [
				'06' => 'CHHUKHA', '08' => 'DAGA', '10' => 'HA', '12' => 'MONGAR', '13' => 'PARO', '15' => 'PUNAKHA', '18' => 'SHEMGANG', '20' => 'THIMPHU', '21' => 'TONGSA', '23' => 'GASA', '24' => 'TRASHI YANGSTE',
			],
			'BW' => [
				'01' => 'CENTRAL', '03' => 'GHANZI', '04' => 'KGALAGADI', '05' => 'KGATLENG', '06' => 'KWENENG', '08' => 'NORTH-EAST', '09' => 'SOUTH-EAST', '10' => 'SOUTHERN', '11' => 'NORTH-WEST',
			],
			'BY' => [
				'01' => 'BRESTSKAYA VOBLASTS\'', '02' => 'HOMYEL\'SKAYA VOBLASTS\'', '03' => 'HRODZYENSKAYA VOBLASTS\'', '05' => 'MINSKAYA VOBLASTS\'', '06' => 'MAHILYOWSKAYA VOBLASTS\'', '07' => 'VITSYEBSKAYA VOBLASTS\'',
			],
			'BZ' => [
				'01' => 'BELIZE', '02' => 'CAYO', '03' => 'COROZAL', '04' => 'ORANGE WALK', '05' => 'STANN CREEK', '06' => 'TOLEDO',
			],
			'CA' => [
				'AB' => 'ALBERTA', 'BC' => 'BRITISH COLUMBIA', 'MB' => 'MANITOBA', 'NB' => 'NEW BRUNSWICK', 'NL' => 'NEWFOUNDLAND AND LABRADOR', 'NS' => 'NOVA SCOTIA', 'ON' => 'ONTARIO', 'PE' => 'PRINCE EDWARD ISLAND', 'QC' => 'QUEBEC', 'SK' => 'SASKATCHEWAN', 'YT' => 'YUKON TERRITORY', 'NT' => 'NORTHWEST TERRITORIES', 'NU' => 'NUNAVUT',
			],
			'CC' => [
				'00' => 'COCOS ISLANDS AND KEELING ISLANDS',
			],
			'CD' => [
				'01' => 'BANDUNDU', '02' => 'EQUATEUR', '03' => 'KASAI-OCCIDENTAL', '04' => 'KASAI-ORIENTAL', '05' => 'KATANGA', '06' => 'KINSHASA', '08' => 'BAS-CONGO', '09' => 'ORIENTALE', '10' => 'MANIEMA', '11' => 'NORD-KIVU', '12' => 'SUD-KIVU',
			],
			'CF' => [
				'01' => 'BAMINGUI-BANGORAN', '02' => 'BASSE-KOTTO', '03' => 'HAUTE-KOTTO', '04' => 'MAMBERE-KADEI', '05' => 'HAUT-MBOMOU', '06' => 'KEMO', '07' => 'LOBAYE', '08' => 'MBOMOU', '09' => 'NANA-MAMBERE', '11' => 'OUAKA', '12' => 'OUHAM', '13' => 'OUHAM-PENDE', '14' => 'CUVETTE-OUEST', '15' => 'NANA-GREBIZI', '16' => 'SANGHA-MBAERE', '17' => 'OMBELLA-MPOKO', '18' => 'BANGUI',
			],
			'CG' => [
				'00' => 'REPUBLIC OF THE CONGO', '01' => 'BOUENZA', '04' => 'KOUILOU', '05' => 'LEKOUMOU', '06' => 'LIKOUALA', '07' => 'NIARI', '08' => 'PLATEAUX', '10' => 'SANGHA', '11' => 'POOL', '12' => 'BRAZZAVILLE', '13' => 'CUVETTE', '14' => 'CUVETTE-OUEST',
			],
			'CH' => [
				'01' => 'AARGAU', '02' => 'AUSSER-RHODEN', '03' => 'BASEL-LANDSCHAFT', '04' => 'BASEL-STADT', '05' => 'BERN', '06' => 'FRIBOURG', '07' => 'GENEVE', '08' => 'GLARUS', '09' => 'GRAUBUNDEN', '10' => 'INNER-RHODEN', '11' => 'LUZERN', '12' => 'NEUCHATEL', '13' => 'NIDWALDEN', '14' => 'OBWALDEN', '15' => 'SANKT GALLEN', '16' => 'SCHAFFHAUSEN', '17' => 'SCHWYZ', '18' => 'SOLOTHURN', '19' => 'THURGAU', '20' => 'TICINO', '21' => 'URI', '22' => 'VALAIS', '23' => 'VAUD', '24' => 'ZUG', '25' => 'ZURICH', '26' => 'JURA',
			],
			'CI' => [
				'74' => 'AGNEBY', '75' => 'BAFING', '76' => 'BAS-SASSANDRA', '77' => 'DENGUELE', '78' => 'DIX-HUIT MONTAGNES', '79' => 'FROMAGER', '80' => 'HAUT-SASSANDRA', '81' => 'LACS', '82' => 'LAGUNES', '83' => 'MARAHOUE', '84' => 'MOYEN-CAVALLY', '85' => 'MOYEN-COMOE', '86' => 'N\'ZI-COMOE', '87' => 'SAVANES', '88' => 'SUD-BANDAMA', '89' => 'SUD-COMOE', '90' => 'VALLEE DU BANDAMA', '91' => 'WORODOUGOU', '92' => 'ZANZAN',
			],
			'CK' => [
				'00' => 'COOK ISLANDS',
			],
			'CL' => [
				'01' => 'VALPARAISO', '02' => 'AISEN DEL GENERAL CARLOS IBANEZ DEL CAMPO', '03' => 'ANTOFAGASTA', '04' => 'ARAUCANIA', '05' => 'ATACAMA', '06' => 'BIO-BIO', '07' => 'COQUIMBO', '08' => 'LIBERTADOR GENERAL BERNARDO O\'HIGGINS', '10' => 'MAGALLANES Y DE LA ANTARTICA CHILENA', '11' => 'MAULE', '12' => 'REGION METROPOLITANA', '14' => 'LOS LAGOS', '15' => 'TARAPACA', '16' => 'ARICA Y PARINACOTA', '17' => 'LOS RIOS',
			],
			'CM' => [
				'04' => 'EST', '05' => 'LITTORAL', '07' => 'NORD-OUEST', '08' => 'OUEST', '09' => 'SUD-OUEST', '10' => 'ADAMAOUA', '11' => 'CENTRE', '12' => 'EXTREME-NORD', '13' => 'NORD', '14' => 'SUD',
			],
			'CN' => [
				'01' => 'ANHUI', '02' => 'ZHEJIANG', '03' => 'JIANGXI', '04' => 'JIANGSU', '05' => 'JILIN', '06' => 'QINGHAI', '07' => 'FUJIAN', '08' => 'HEILONGJIANG', '09' => 'HENAN', '10' => 'HEBEI', '11' => 'HUNAN', '12' => 'HUBEI', '13' => 'XINJIANG', '14' => 'XIZANG', '15' => 'GANSU', '16' => 'GUANGXI', '18' => 'GUIZHOU', '19' => 'LIAONING', '20' => 'NEI MONGOL', '21' => 'NINGXIA', '22' => 'BEIJING', '23' => 'SHANGHAI', '24' => 'SHANXI', '25' => 'SHANDONG', '26' => 'SHAANXI', '28' => 'TIANJIN', '29' => 'YUNNAN', '30' => 'GUANGDONG', '31' => 'HAINAN', '32' => 'SICHUAN', '33' => 'CHONGQING',
			],
			'CO' => [
				'01' => 'AMAZONAS', '02' => 'ANTIOQUIA', '03' => 'ARAUCA', '04' => 'ATLANTICO', '08' => 'CAQUETA', '09' => 'CAUCA', '10' => 'CESAR', '11' => 'CHOCO', '12' => 'CORDOBA', '14' => 'GUAVIARE', '15' => 'GUAINIA', '16' => 'HUILA', '17' => 'LA GUAJIRA', '19' => 'META', '20' => 'NARINO', '21' => 'NORTE DE SANTANDER', '22' => 'PUTUMAYO', '23' => 'QUINDIO', '24' => 'RISARALDA', '25' => 'SAN ANDRES Y PROVIDENCIA', '26' => 'SANTANDER', '27' => 'SUCRE', '28' => 'TOLIMA', '29' => 'VALLE DEL CAUCA', '30' => 'VAUPES', '31' => 'VICHADA', '32' => 'CASANARE', '33' => 'CUNDINAMARCA', '34' => 'DISTRITO ESPECIAL', '35' => 'BOLIVAR', '36' => 'BOYACA', '37' => 'CALDAS', '38' => 'MAGDALENA',
			],
			'CR' => [
				'01' => 'ALAJUELA', '02' => 'CARTAGO', '03' => 'GUANACASTE', '04' => 'HEREDIA', '06' => 'LIMON', '07' => 'PUNTARENAS', '08' => 'SAN JOSE',
			],
			'CU' => [
				'01' => 'PINAR DEL RIO', '02' => 'CIUDAD DE LA HABANA', '03' => 'MATANZAS', '04' => 'ISLA DE LA JUVENTUD', '05' => 'CAMAGUEY', '07' => 'CIEGO DE AVILA', '08' => 'CIENFUEGOS', '09' => 'GRANMA', '10' => 'GUANTANAMO', '11' => 'LA HABANA', '12' => 'HOLGUIN', '13' => 'LAS TUNAS', '14' => 'SANCTI SPIRITUS', '15' => 'SANTIAGO DE CUBA', '16' => 'VILLA CLARA', '17' => 'ARTEMISA', '18' => 'MAYABEQUE',
			],
			'CV' => [
				'01' => 'BOA VISTA', '02' => 'BRAVA', '04' => 'MAIO', '05' => 'PAUL', '07' => 'RIBEIRA GRANDE', '08' => 'SAL', '11' => 'SAO VICENTE', '13' => 'MOSTEIROS', '14' => 'PRAIA', '15' => 'SANTA CATARINA', '16' => 'SANTA CRUZ', '17' => 'SAO DOMINGOS', '18' => 'SAO FILIPE', '19' => 'SAO MIGUEL', '20' => 'TARRAFAL', '21' => 'PORTO NOVO', '22' => 'RIBEIRA BRAVA', '23' => 'RIBEIRA GRANDE DE SANTIAGO', '24' => 'SANTA CATARINA DO FOGO', '26' => 'SAO SALVADOR DO MUNDO', '27' => 'TARRAFAL DE SAO NICOLAU',
			],
			'CW' => [
				'00' => 'CURACAO',
			],
			'CX' => [
				'00' => 'CHRISTMAS ISLAND',
			],
			'CY' => [
				'01' => 'FAMAGUSTA', '02' => 'KYRENIA', '03' => 'LARNACA', '04' => 'NICOSIA', '05' => 'LIMASSOL', '06' => 'PAPHOS',
			],
			'CZ' => [
				'52' => 'HLAVNI MESTO PRAHA', '78' => 'JIHOMORAVSKY KRAJ', '79' => 'JIHOCESKY KRAJ', '80' => 'VYSOCINA KRAJ', '81' => 'KARLOVARSKY KRAJ', '82' => 'KRALOVEHRADECKY KRAJ', '83' => 'LIBERECKY KRAJ', '84' => 'OLOMOUCKY KRAJ', '85' => 'MORAVSKOSLEZSKY KRAJ', '86' => 'PARDUBICKY KRAJ', '87' => 'PLZENSKY KRAJ', '88' => 'STREDOCESKY KRAJ', '89' => 'USTECKY KRAJ', '90' => 'ZLINSKY KRAJ',
			],
			'DE' => [
				'01' => 'BADEN-WURTTEMBERG', '02' => 'BAYERN', '03' => 'BREMEN', '04' => 'HAMBURG', '05' => 'HESSEN', '06' => 'NIEDERSACHSEN', '07' => 'NORDRHEIN-WESTFALEN', '08' => 'RHEINLAND-PFALZ', '09' => 'SAARLAND', '10' => 'SCHLESWIG-HOLSTEIN', '11' => 'BRANDENBURG', '12' => 'MECKLENBURG-VORPOMMERN', '13' => 'SACHSEN', '14' => 'SACHSEN-ANHALT', '15' => 'THURINGEN', '16' => 'BERLIN',
			],
			'DJ' => [
				'01' => 'ALI SABIEH', '04' => 'OBOCK', '05' => 'TADJOURA', '06' => 'DIKHIL', '07' => 'DJIBOUTI', '08' => 'ARTA',
			],
			'DK' => [
				'17' => 'HOVEDSTADEN', '18' => 'MIDTJYLLAND', '19' => 'NORDJYLLAND', '20' => 'SJELLAND', '21' => 'SYDDANMARK',
			],
			'DM' => [
				'02' => 'SAINT ANDREW', '03' => 'SAINT DAVID', '04' => 'SAINT GEORGE', '05' => 'SAINT JOHN', '06' => 'SAINT JOSEPH', '07' => 'SAINT LUKE', '08' => 'SAINT MARK', '09' => 'SAINT PATRICK', '10' => 'SAINT PAUL',
			],
			'DO' => [
				'01' => 'AZUA', '02' => 'BAORUCO', '03' => 'BARAHONA', '04' => 'DAJABON', '06' => 'DUARTE', '08' => 'ESPAILLAT', '09' => 'INDEPENDENCIA', '10' => 'LA ALTAGRACIA', '11' => 'ELIAS PINA', '12' => 'LA ROMANA', '14' => 'MARIA TRINIDAD SANCHEZ', '15' => 'MONTE CRISTI', '16' => 'PEDERNALES', '18' => 'PUERTO PLATA', '19' => 'SALCEDO', '20' => 'SAMANA', '21' => 'SANCHEZ RAMIREZ', '23' => 'SAN JUAN', '24' => 'SAN PEDRO DE MACORIS', '25' => 'SANTIAGO', '26' => 'SANTIAGO RODRIGUEZ', '27' => 'VALVERDE', '28' => 'EL SEIBO', '29' => 'HATO MAYOR', '30' => 'LA VEGA', '31' => 'MONSENOR NOUEL', '32' => 'MONTE PLATA', '33' => 'SAN CRISTOBAL', '34' => 'DISTRITO NACIONAL', '35' => 'PERAVIA',
			],
			'DZ' => [
				'01' => 'ALGER', '03' => 'BATNA', '04' => 'CONSTANTINE', '06' => 'MEDEA', '07' => 'MOSTAGANEM', '09' => 'ORAN', '10' => 'SAIDA', '12' => 'SETIF', '13' => 'TIARET', '14' => 'TIZI OUZOU', '15' => 'TLEMCEN', '18' => 'BEJAIA', '19' => 'BISKRA', '20' => 'BLIDA', '21' => 'BOUIRA', '22' => 'DJELFA', '23' => 'GUELMA', '25' => 'LAGHOUAT', '26' => 'MASCARA', '27' => 'M\'SILA', '29' => 'OUM EL BOUAGHI', '30' => 'SIDI BEL ABBES', '31' => 'SKIKDA', '33' => 'TEBESSA', '34' => 'ADRAR', '35' => 'AIN DEFLA', '36' => 'AIN TEMOUCHENT', '37' => 'ANNABA', '38' => 'BECHAR', '39' => 'BORDJ BOU ARRERIDJ', '40' => 'BOUMERDES', '41' => 'CHLEF', '42' => 'EL BAYADH', '43' => 'EL OUED', '44' => 'EL TARF', '45' => 'GHARDAIA', '46' => 'ILLIZI', '47' => 'KHENCHELA', '48' => 'MILA', '49' => 'NAAMA', '50' => 'OUARGLA', '51' => 'RELIZANE', '52' => 'SOUK AHRAS', '53' => 'TAMANGHASSET', '54' => 'TINDOUF', '55' => 'TIPAZA', '56' => 'TISSEMSILT',
			],
			'EC' => [
				'01' => 'GALAPAGOS', '02' => 'AZUAY', '03' => 'BOLIVAR', '04' => 'CANAR', '05' => 'CARCHI', '06' => 'CHIMBORAZO', '07' => 'COTOPAXI', '08' => 'EL ORO', '09' => 'ESMERALDAS', '10' => 'GUAYAS', '11' => 'IMBABURA', '12' => 'LOJA', '13' => 'LOS RIOS', '14' => 'MANABI', '15' => 'MORONA-SANTIAGO', '17' => 'PASTAZA', '18' => 'PICHINCHA', '19' => 'TUNGURAHUA', '20' => 'ZAMORA-CHINCHIPE', '22' => 'SUCUMBIOS', '23' => 'NAPO', '24' => 'ORELLANA', '25' => 'SANTA ELENA',
			],
			'EE' => [
				'01' => 'HARJUMAA', '02' => 'HIIUMAA', '03' => 'IDA-VIRUMAA', '04' => 'JARVAMAA', '05' => 'JOGEVAMAA', '07' => 'LAANEMAA', '08' => 'LAANE-VIRUMAA', '11' => 'PARNUMAA', '12' => 'POLVAMAA', '13' => 'RAPLAMAA', '14' => 'SAAREMAA', '18' => 'TARTUMAA', '19' => 'VALGAMAA', '20' => 'VILJANDIMAA', '21' => 'VORUMAA',
			],
			'EG' => [
				'01' => 'AD DAQAHLIYAH', '02' => 'AL BAHR AL AHMAR', '03' => 'AL BUHAYRAH', '04' => 'AL FAYYUM', '05' => 'AL GHARBIYAH', '06' => 'AL ISKANDARIYAH', '07' => 'AL ISMA\'ILIYAH', '08' => 'AL JIZAH', '09' => 'AL MINUFIYAH', '10' => 'AL MINYA', '11' => 'AL QAHIRAH', '12' => 'AL QALYUBIYAH', '13' => 'AL WADI AL JADID', '14' => 'ASH SHARQIYAH', '15' => 'AS SUWAYS', '16' => 'ASWAN', '17' => 'ASYUT', '18' => 'BANI SUWAYF', '19' => 'BUR SA\'ID', '20' => 'DUMYAT', '21' => 'KAFR ASH SHAYKH', '22' => 'MATRUH', '23' => 'QINA', '24' => 'SUHAJ', '26' => 'JANUB SINA\'', '27' => 'SHAMAL SINA\'', '28' => 'MUHAFAZAT AL UQSUR',
			],
			'EH' => [
				'00' => 'WESTERN SAHARA', 'CE' => 'OUED ED-DAHAB-LAGOUIRA',
			],
			'ER' => [
				'01' => 'ANSEBA', '02' => 'DEBUB', '03' => 'DEBUBAWI K\'EYIH BAHRI', '04' => 'GASH BARKA', '05' => 'MA\'AKEL', '06' => 'SEMENAWI K\'EYIH BAHRI',
			],
			'ES' => [
				'07' => 'ISLAS BALEARES', '27' => 'LA RIOJA', '29' => 'MADRID', '31' => 'MURCIA', '32' => 'NAVARRA', '34' => 'ASTURIAS', '39' => 'CANTABRIA', '51' => 'ANDALUCIA', '52' => 'ARAGON', '53' => 'CANARIAS', '54' => 'CASTILLA-LA MANCHA', '55' => 'CASTILLA Y LEON', '56' => 'CATALONIA', '57' => 'EXTREMADURA', '58' => 'GALICIA', '59' => 'PAIS VASCO', '60' => 'COMUNIDAD VALENCIANA', 'CE' => 'CEUTA', 'ML' => 'MELILLA',
			],
			'ET' => [
				'44' => 'ADIS ABEBA', '45' => 'AFAR', '46' => 'AMARA', '47' => 'BINSHANGUL GUMUZ', '48' => 'DIRE DAWA', '49' => 'GAMBELA HIZBOCH', '50' => 'HARERI HIZB', '51' => 'OROMIYA', '52' => 'SUMALE', '53' => 'TIGRAY', '54' => 'YEDEBUB BIHEROCH BIHERESEBOCH NA HIZBOCH',
			],
			'FI' => [
				'06' => 'LAPLAND', '08' => 'OULU', '13' => 'SOUTHERN FINLAND', '14' => 'EASTERN FINLAND', '15' => 'WESTERN FINLAND',
			],
			'FJ' => [
				'01' => 'CENTRAL', '03' => 'NORTHERN', '05' => 'WESTERN',
			],
			'FK' => [
				'00' => 'FALKLAND ISLANDS',
			],
			'FM' => [
				'01' => 'KOSRAE', '02' => 'POHNPEI', '03' => 'CHUUK', '04' => 'YAP',
			],
			'FO' => [
				'NO' => 'NORDOYAR', 'OS' => 'EYSTUROY', 'SA' => 'SANDOY', 'ST' => 'STREYMOY', 'SU' => 'SUDUROY', 'VG' => 'VAGAR',
			],
			'FR' => [
				'97' => 'AQUITAINE', '98' => 'AUVERGNE', '99' => 'BASSE-NORMANDIE', 'A1' => 'BOURGOGNE', 'A2' => 'BRETAGNE', 'A3' => 'CENTRE', 'A4' => 'CHAMPAGNE-ARDENNE', 'A5' => 'CORSE', 'A6' => 'FRANCHE-COMTE', 'A7' => 'HAUTE-NORMANDIE', 'A8' => 'ILE-DE-FRANCE', 'A9' => 'LANGUEDOC-ROUSSILLON', 'B1' => 'LIMOUSIN', 'B2' => 'LORRAINE', 'B3' => 'MIDI-PYRENEES', 'B4' => 'NORD-PAS-DE-CALAIS', 'B5' => 'PAYS DE LA LOIRE', 'B6' => 'PICARDIE', 'B7' => 'POITOU-CHARENTES', 'B8' => 'PROVENCE-ALPES-COTE D\'AZUR', 'B9' => 'RHONE-ALPES', 'C1' => 'ALSACE',
			],
			'GA' => [
				'01' => 'ESTUAIRE', '02' => 'HAUT-OGOOUE', '03' => 'MOYEN-OGOOUE', '04' => 'NGOUNIE', '05' => 'NYANGA', '06' => 'OGOOUE-IVINDO', '07' => 'OGOOUE-LOLO', '08' => 'OGOOUE-MARITIME', '09' => 'WOLEU-NTEM',
			],
			'GB' => [
				'01' => 'ENGLAND', '02' => 'NORTHERN IRELAND', '03' => 'SCOTLAND', '04' => 'WALES',
			],
			'GD' => [
				'01' => 'SAINT ANDREW', '02' => 'SAINT DAVID', '03' => 'SAINT GEORGE', '04' => 'SAINT JOHN', '05' => 'SAINT MARK', '06' => 'SAINT PATRICK',
			],
			'GE' => [
				'02' => 'ABKHAZIA', '04' => 'AJARIA', '06' => 'AKHALK\'ALAK\'IS RAIONI', '11' => 'BAGHDAT\'IS RAIONI', '13' => 'BORJOMIS RAIONI', '22' => 'GORIS RAIONI', '24' => 'JAVIS RAIONI', '25' => 'K\'ARELIS RAIONI', '28' => 'KHASHURIS RAIONI', '51' => 'T\'BILISI', '61' => 'VANIS RAIONI', '65' => 'GURIA', '66' => 'IMERETI', '67' => 'KAKHETI', '68' => 'KVEMO KARTLI', '69' => 'MTSKHETA-MTIANETI', '70' => 'RACHA-LECHKHUMI AND KVEMO SVANETI', '71' => 'SAMEGRELO AND ZEMO SVANETI', '72' => 'SAMTSKHE-JAVAKHETI', '73' => 'SHIDA KARTLI',
			],
			'GF' => [
				'GF' => 'GUYANE',
			],
			'GG' => [
				'00' => 'GUERNSEY (GENERAL)',
			],
			'GH' => [
				'01' => 'GREATER ACCRA', '02' => 'ASHANTI', '03' => 'BRONG-AHAFO', '04' => 'CENTRAL', '05' => 'EASTERN', '06' => 'NORTHERN', '08' => 'VOLTA', '09' => 'WESTERN', '10' => 'UPPER EAST', '11' => 'UPPER WEST',
			],
			'GI' => [
				'00' => 'GIBRALTAR',
			],
			'GL' => [
				'03' => 'VESTGRONLAND', '04' => 'KUJALLEQ', '05' => 'QAASUITSUP', '06' => 'QEQQATA', '07' => 'SERMERSOOQ',
			],
			'GM' => [
				'01' => 'BANJUL', '02' => 'LOWER RIVER', '03' => 'CENTRAL RIVER', '04' => 'UPPER RIVER', '05' => 'WESTERN', '07' => 'NORTH BANK',
			],
			'GN' => [
				'01' => 'BEYLA', '02' => 'BOFFA', '03' => 'BOKE', '04' => 'CONAKRY', '05' => 'DABOLA', '06' => 'DALABA', '07' => 'DINGUIRAYE', '09' => 'FARANAH', '10' => 'FORECARIAH', '11' => 'FRIA', '12' => 'GAOUAL', '13' => 'GUECKEDOU', '15' => 'KEROUANE', '16' => 'KINDIA', '17' => 'KISSIDOUGOU', '18' => 'KOUNDARA', '19' => 'KOUROUSSA', '21' => 'MACENTA', '22' => 'MALI', '23' => 'MAMOU', '25' => 'PITA', '27' => 'TELIMELE', '28' => 'TOUGUE', '29' => 'YOMOU', '30' => 'COYAH', '31' => 'DUBREKA', '32' => 'KANKAN', '33' => 'KOUBIA', '34' => 'LABE', '35' => 'LELOUMA', '36' => 'LOLA', '37' => 'MANDIANA', '38' => 'NZEREKORE', '39' => 'SIGUIRI',
			],
			'GP' => [
				'GP' => 'GUADELOUPE',
			],
			'GQ' => [
				'03' => 'ANNOBON', '04' => 'BIOKO NORTE', '05' => 'BIOKO SUR', '06' => 'CENTRO SUR', '07' => 'KIE-NTEM', '08' => 'LITORAL', '09' => 'WELE-NZAS',
			],
			'GR' => [
				'01' => 'EVROS', '02' => 'RODHOPI', '03' => 'XANTHI', '04' => 'DRAMA', '05' => 'SERRAI', '06' => 'KILKIS', '07' => 'PELLA', '08' => 'FLORINA', '09' => 'KASTORIA', '10' => 'GREVENA', '11' => 'KOZANI', '12' => 'IMATHIA', '13' => 'THESSALONIKI', '14' => 'KAVALA', '15' => 'KHALKIDHIKI', '16' => 'PIERIA', '17' => 'IOANNINA', '18' => 'THESPROTIA', '19' => 'PREVEZA', '20' => 'ARTA', '21' => 'LARISA', '22' => 'TRIKALA', '23' => 'KARDHITSA', '24' => 'MAGNISIA', '25' => 'KERKIRA', '26' => 'LEVKAS', '27' => 'KEFALLINIA', '28' => 'ZAKINTHOS', '29' => 'FTHIOTIS', '30' => 'EVRITANIA', '31' => 'AITOLIA KAI AKARNANIA', '32' => 'FOKIS', '33' => 'VOIOTIA', '34' => 'EVVOIA', '35' => 'ATTIKI', '36' => 'ARGOLIS', '37' => 'KORINTHIA', '38' => 'AKHAIA', '39' => 'ILIA', '40' => 'MESSINIA', '41' => 'ARKADHIA', '42' => 'LAKONIA', '43' => 'KHANIA', '44' => 'RETHIMNI', '45' => 'IRAKLION', '46' => 'LASITHI', '47' => 'DHODHEKANISOS', '48' => 'SAMOS', '49' => 'KIKLADHES', '50' => 'KHIOS', '51' => 'LESVOS',
			],
			'GS' => [
				'00' => 'SOUTH GEORGIA AND THE SOUTH SANDWICH ISLANDS',
			],
			'GT' => [
				'01' => 'ALTA VERAPAZ', '02' => 'BAJA VERAPAZ', '03' => 'CHIMALTENANGO', '04' => 'CHIQUIMULA', '05' => 'EL PROGRESO', '06' => 'ESCUINTLA', '07' => 'GUATEMALA', '08' => 'HUEHUETENANGO', '09' => 'IZABAL', '10' => 'JALAPA', '11' => 'JUTIAPA', '12' => 'PETEN', '13' => 'QUETZALTENANGO', '14' => 'QUICHE', '15' => 'RETALHULEU', '16' => 'SACATEPEQUEZ', '17' => 'SAN MARCOS', '18' => 'SANTA ROSA', '19' => 'SOLOLA', '20' => 'SUCHITEPEQUEZ', '21' => 'TOTONICAPAN', '22' => 'ZACAPA',
			],
			'GU' => [
				'AH' => 'AGANA HEIGHTS MUNICIPALITY', 'AN' => 'HAGATNA MUNICIPALITY', 'AS' => 'ASAN-MAINA MUNICIPALITY', 'AT' => 'AGAT MUNICIPALITY', 'BA' => 'BARRIGADA MUNICIPALITY', 'CP' => 'CHALAN PAGO-ORDOT MUNICIPALITY', 'DD' => 'DEDEDO MUNICIPALITY', 'IN' => 'INARAJAN MUNICIPALITY', 'MA' => 'MANGILAO MUNICIPALITY', 'ME' => 'MERIZO MUNICIPALITY', 'MT' => 'MONGMONG-TOTO-MAITE MUNICIPALITY', 'PI' => 'PITI MUNICIPALITY', 'SJ' => 'SINAJANA MUNICIPALITY', 'SR' => 'SANTA RITA MUNICIPALITY', 'TF' => 'TALOFOFO MUNICIPALITY', 'TM' => 'TAMUNING-TUMON-HARMON MUNICIPALITY', 'UM' => 'UMATAC MUNICIPALITY', 'YG' => 'YIGO MUNICIPALITY', 'YN' => 'YONA MUNICIPALITY',
			],
			'GW' => [
				'01' => 'BAFATA', '02' => 'QUINARA', '04' => 'OIO', '05' => 'BOLAMA', '06' => 'CACHEU', '07' => 'TOMBALI', '10' => 'GABU', '11' => 'BISSAU', '12' => 'BIOMBO',
			],
			'GY' => [
				'11' => 'CUYUNI-MAZARUNI', '12' => 'DEMERARA-MAHAICA', '13' => 'EAST BERBICE-CORENTYNE', '14' => 'ESSEQUIBO ISLANDS-WEST DEMERARA', '15' => 'MAHAICA-BERBICE', '16' => 'POMEROON-SUPENAAM', '18' => 'UPPER DEMERARA-BERBICE',
			],
			'HK' => [
				'HK' => 'HONG KONG (SAR)',
			],
			'HN' => [
				'01' => 'ATLANTIDA', '02' => 'CHOLUTECA', '03' => 'COLON', '04' => 'COMAYAGUA', '05' => 'COPAN', '06' => 'CORTES', '07' => 'EL PARAISO', '08' => 'FRANCISCO MORAZAN', '09' => 'GRACIAS A DIOS', '10' => 'INTIBUCA', '11' => 'ISLAS DE LA BAHIA', '12' => 'LA PAZ', '13' => 'LEMPIRA', '14' => 'OCOTEPEQUE', '15' => 'OLANCHO', '16' => 'SANTA BARBARA', '17' => 'VALLE', '18' => 'YORO',
			],
			'HR' => [
				'01' => 'BJELOVARSKO-BILOGORSKA', '02' => 'BRODSKO-POSAVSKA', '03' => 'DUBROVACKO-NERETVANSKA', '04' => 'ISTARSKA', '05' => 'KARLOVACKA', '06' => 'KOPRIVNICKO-KRIZEVACKA', '07' => 'KRAPINSKO-ZAGORSKA', '08' => 'LICKO-SENJSKA', '09' => 'MEDIMURSKA', '10' => 'OSJECKO-BARANJSKA', '11' => 'POZESKO-SLAVONSKA', '12' => 'PRIMORSKO-GORANSKA', '13' => 'SIBENSKO-KNINSKA', '14' => 'SISACKO-MOSLAVACKA', '15' => 'SPLITSKO-DALMATINSKA', '16' => 'VARAZDINSKA', '17' => 'VIROVITICKO-PODRAVSKA', '18' => 'VUKOVARSKO-SRIJEMSKA', '19' => 'ZADARSKA', '20' => 'ZAGREBACKA', '21' => 'GRAD ZAGREB',
			],
			'HT' => [
				'03' => 'NORD-OUEST', '06' => 'ARTIBONITE', '07' => 'CENTRE', '09' => 'NORD', '10' => 'NORD-EST', '11' => 'OUEST', '12' => 'SUD', '13' => 'SUD-EST', '14' => 'GRAND\' ANSE', '15' => 'NIPPES',
			],
			'HU' => [
				'01' => 'BACS-KISKUN', '02' => 'BARANYA', '03' => 'BEKES', '04' => 'BORSOD-ABAUJ-ZEMPLEN', '05' => 'BUDAPEST', '06' => 'CSONGRAD', '08' => 'FEJER', '09' => 'GYOR-MOSON-SOPRON', '10' => 'HAJDU-BIHAR', '11' => 'HEVES', '12' => 'KOMAROM-ESZTERGOM', '14' => 'NOGRAD', '16' => 'PEST', '17' => 'SOMOGY', '18' => 'SZABOLCS-SZATMAR-BEREG', '20' => 'JASZ-NAGYKUN-SZOLNOK', '21' => 'TOLNA', '22' => 'VAS', '23' => 'VESZPREM', '24' => 'ZALA',
			],
			'ID' => [
				'01' => 'ACEH', '02' => 'BALI', '03' => 'BENGKULU', '04' => 'JAKARTA RAYA', '05' => 'JAMBI', '07' => 'JAWA TENGAH', '08' => 'JAWA TIMUR', '10' => 'YOGYAKARTA', '11' => 'KALIMANTAN BARAT', '12' => 'KALIMANTAN SELATAN', '13' => 'KALIMANTAN TENGAH', '14' => 'KALIMANTAN TIMUR', '15' => 'LAMPUNG', '17' => 'NUSA TENGGARA BARAT', '18' => 'NUSA TENGGARA TIMUR', '21' => 'SULAWESI TENGAH', '22' => 'SULAWESI TENGGARA', '24' => 'SUMATERA BARAT', '26' => 'SUMATERA UTARA', '28' => 'MALUKU', '29' => 'MALUKU UTARA', '30' => 'JAWA BARAT', '31' => 'SULAWESI UTARA', '32' => 'SUMATERA SELATAN', '33' => 'BANTEN', '34' => 'GORONTALO', '35' => 'KEPULAUAN BANGKA BELITUNG', '36' => 'PAPUA', '37' => 'RIAU', '38' => 'SULAWESI SELATAN', '39' => 'IRIAN JAYA BARAT', '40' => 'KEPULAUAN RIAU', '41' => 'SULAWESI BARAT',
			],
			'IE' => [
				'01' => 'CARLOW', '02' => 'CAVAN', '03' => 'CLARE', '04' => 'CORK', '06' => 'DONEGAL', '07' => 'DUBLIN', '10' => 'GALWAY', '11' => 'KERRY', '12' => 'KILDARE', '13' => 'KILKENNY', '14' => 'LEITRIM', '15' => 'LAOIS', '16' => 'LIMERICK', '18' => 'LONGFORD', '19' => 'LOUTH', '20' => 'MAYO', '21' => 'MEATH', '22' => 'MONAGHAN', '23' => 'OFFALY', '24' => 'ROSCOMMON', '25' => 'SLIGO', '26' => 'TIPPERARY', '27' => 'WATERFORD', '29' => 'WESTMEATH', '30' => 'WEXFORD', '31' => 'WICKLOW', '33' => 'DUBLIN CITY', '35' => 'FINGAL', '38' => 'TIPPERARY NORTH RIDING', '39' => 'SOUTH DUBLIN',
			],
			'IL' => [
				'01' => 'HADAROM', '02' => 'HAMERKAZ', '03' => 'HAZAFON', '04' => 'HEFA', '05' => 'TEL AVIV', '06' => 'YERUSHALAYIM',
			],
			'IM' => [
				'00' => 'ISLE OF MAN',
			],
			'IN' => [
				'01' => 'ANDAMAN AND NICOBAR ISLANDS', '02' => 'ANDHRA PRADESH', '03' => 'ASSAM', '05' => 'CHANDIGARH', '06' => 'DADRA AND NAGAR HAVELI', '07' => 'DELHI', '09' => 'GUJARAT', '10' => 'HARYANA', '11' => 'HIMACHAL PRADESH', '12' => 'JAMMU AND KASHMIR', '13' => 'KERALA', '14' => 'LAKSHADWEEP', '16' => 'MAHARASHTRA', '17' => 'MANIPUR', '18' => 'MEGHALAYA', '19' => 'KARNATAKA', '20' => 'NAGALAND', '21' => 'ORISSA', '22' => 'PUDUCHERRY', '23' => 'PUNJAB', '24' => 'RAJASTHAN', '25' => 'TAMIL NADU', '26' => 'TRIPURA', '28' => 'WEST BENGAL', '29' => 'SIKKIM', '30' => 'ARUNACHAL PRADESH', '31' => 'MIZORAM', '32' => 'DAMAN AND DIU', '33' => 'GOA', '34' => 'BIHAR', '35' => 'MADHYA PRADESH', '36' => 'UTTAR PRADESH', '37' => 'CHHATTISGARH', '38' => 'JHARKHAND', '39' => 'UTTARAKHAND',
			],
			'IO' => [
				'00' => 'BRITISH INDIAN OCEAN TERRITORY',
			],
			'IQ' => [
				'01' => 'AL ANBAR', '02' => 'AL BASRAH', '03' => 'AL MUTHANNA', '04' => 'AL QADISIYAH', '05' => 'AS SULAYMANIYAH', '06' => 'BABIL', '07' => 'BAGHDAD', '08' => 'DAHUK', '09' => 'DHI QAR', '10' => 'DIYALA', '11' => 'ARBIL', '12' => 'KARBALA\'', '13' => 'AT TA\'MIM', '14' => 'MAYSAN', '15' => 'NINAWA', '16' => 'WASIT', '17' => 'AN NAJAF', '18' => 'SALAH AD DIN',
			],
			'IR' => [
				'01' => 'AZARBAYJAN-E BAKHTARI', '03' => 'CHAHAR MAHALL VA BAKHTIARI', '04' => 'SISTAN VA BALUCHESTAN', '05' => 'KOHKILUYEH VA BUYER AHMADI', '07' => 'FARS', '08' => 'GILAN', '09' => 'HAMADAN', '10' => 'ILAM', '11' => 'HORMOZGAN', '13' => 'BAKHTARAN', '15' => 'KHUZESTAN', '16' => 'KORDESTAN', '22' => 'BUSHEHR', '23' => 'LORESTAN', '25' => 'SEMNAN', '26' => 'TEHRAN', '28' => 'ESFAHAN', '29' => 'KERMAN', '32' => 'ARDABIL', '33' => 'EAST AZARBAIJAN', '34' => 'MARKAZI', '35' => 'MAZANDARAN', '36' => 'ZANJAN', '37' => 'GOLESTAN', '38' => 'QAZVIN', '39' => 'QOM', '40' => 'YAZD', '41' => 'KHORASAN-E JANUBI', '42' => 'KHORASAN-E RAZAVI', '43' => 'KHORASAN-E SHEMALI', '44' => 'ALBORZ',
			],
			'IS' => [
				'38' => 'AUSTURLAND', '39' => 'HOFUOBORGARSVAOIO', '40' => 'NOROURLAND EYSTRA', '41' => 'NOROURLAND VESTRA', '42' => 'SUOURLAND', '43' => 'SUOURNES', '44' => 'VESTFIROIR', '45' => 'VESTURLAND',
			],
			'IT' => [
				'01' => 'ABRUZZI', '02' => 'BASILICATA', '03' => 'CALABRIA', '04' => 'CAMPANIA', '05' => 'EMILIA-ROMAGNA', '06' => 'FRIULI-VENEZIA GIULIA', '07' => 'LAZIO', '08' => 'LIGURIA', '09' => 'LOMBARDIA', '10' => 'MARCHE', '11' => 'MOLISE', '12' => 'PIEMONTE', '13' => 'PUGLIA', '14' => 'SARDEGNA', '15' => 'SICILIA', '16' => 'TOSCANA', '17' => 'TRENTINO-ALTO ADIGE', '18' => 'UMBRIA', '19' => 'VALLE D\'AOSTA', '20' => 'VENETO',
			],
			'JE' => [
				'00' => 'JERSEY',
			],
			'JM' => [
				'01' => 'CLARENDON', '02' => 'HANOVER', '04' => 'MANCHESTER', '07' => 'PORTLAND', '08' => 'SAINT ANDREW', '09' => 'SAINT ANN', '10' => 'SAINT CATHERINE', '11' => 'SAINT ELIZABETH', '12' => 'SAINT JAMES', '13' => 'SAINT MARY', '14' => 'SAINT THOMAS', '15' => 'TRELAWNY', '16' => 'WESTMORELAND', '17' => 'KINGSTON',
			],
			'JO' => [
				'02' => 'AL BALQA\'', '09' => 'AL KARAK', '12' => 'AT TAFILAH', '15' => 'AL MAFRAQ', '16' => '\'AMMAN', '17' => 'AZ ZARQA\'', '18' => 'IRBID', '19' => 'MA\'AN', '21' => 'AL \'AQABAH', '23' => 'MADABA',
			],
			'JP' => [
				'01' => 'AICHI', '02' => 'AKITA', '03' => 'AOMORI', '04' => 'CHIBA', '05' => 'EHIME', '06' => 'FUKUI', '07' => 'FUKUOKA', '08' => 'FUKUSHIMA', '09' => 'GIFU', '10' => 'GUMMA', '11' => 'HIROSHIMA', '12' => 'HOKKAIDO', '13' => 'HYOGO', '14' => 'IBARAKI', '15' => 'ISHIKAWA', '16' => 'IWATE', '17' => 'KAGAWA', '18' => 'KAGOSHIMA', '19' => 'KANAGAWA', '20' => 'KOCHI', '21' => 'KUMAMOTO', '22' => 'KYOTO', '23' => 'MIE', '24' => 'MIYAGI', '25' => 'MIYAZAKI', '26' => 'NAGANO', '27' => 'NAGASAKI', '28' => 'NARA', '29' => 'NIIGATA', '30' => 'OITA', '31' => 'OKAYAMA', '32' => 'OSAKA', '33' => 'SAGA', '34' => 'SAITAMA', '35' => 'SHIGA', '36' => 'SHIMANE', '37' => 'SHIZUOKA', '38' => 'TOCHIGI', '39' => 'TOKUSHIMA', '40' => 'TOKYO', '41' => 'TOTTORI', '42' => 'TOYAMA', '43' => 'WAKAYAMA', '44' => 'YAMAGATA', '45' => 'YAMAGUCHI', '46' => 'YAMANASHI', '47' => 'OKINAWA',
			],
			'KE' => [
				'01' => 'CENTRAL', '02' => 'COAST', '03' => 'EASTERN', '05' => 'NAIROBI AREA', '06' => 'NORTH-EASTERN', '07' => 'NYANZA', '08' => 'RIFT VALLEY', '09' => 'WESTERN',
			],
			'KG' => [
				'01' => 'BISHKEK', '02' => 'CHUY', '03' => 'JALAL-ABAD', '04' => 'NARYN', '06' => 'TALAS', '07' => 'YSYK-KOL', '08' => 'OSH', '09' => 'BATKEN',
			],
			'KH' => [
				'02' => 'KAMPONG CHAM', '03' => 'KAMPONG CHHNANG', '04' => 'KAMPONG SPEU', '05' => 'KAMPONG THOM', '07' => 'KANDAL', '08' => 'KOH KONG', '09' => 'KRATIE', '10' => 'MONDULKIRI', '12' => 'PURSAT', '13' => 'PREAH VIHEAR', '14' => 'PREY VENG', '17' => 'STUNG TRENG', '18' => 'SVAY RIENG', '19' => 'TAKEO', '06' => 'KAMPOT', '22' => 'PHNOM PENH', '23' => 'RATANAKIRI', '16' => 'SIEM REAP', '25' => 'BANTEAY MEANCHEY', '26' => 'KEP', '27' => 'ODDAR MEANCHEY', '28' => 'PREAH SIHANOUK', '29' => 'BATTAMBANG', '30' => 'PAILIN',
			],
			'KI' => [
				'01' => 'GILBERT ISLANDS', '02' => 'LINE ISLANDS',
			],
			'KM' => [
				'01' => 'ANJOUAN', '02' => 'GRANDE COMORE', '03' => 'MOHELI',
			],
			'KN' => [
				'03' => 'SAINT GEORGE BASSETERRE', '10' => 'SAINT PAUL CHARLESTOWN',
			],
			'KP' => [
				'01' => 'CHAGANG-DO', '03' => 'HAMGYONG-NAMDO', '06' => 'HWANGHAE-NAMDO', '07' => 'HWANGHAE-BUKTO', '09' => 'KANGWON-DO', '11' => 'P\'YONGAN-BUKTO', '12' => 'P\'YONGYANG-SI', '13' => 'YANGGANG-DO', '15' => 'P\'YONGAN-NAMDO', '17' => 'HAMGYONG-BUKTO', '18' => 'NAJIN SONBONG-SI',
			],
			'KR' => [
				'01' => 'CHEJU-DO', '03' => 'CHOLLA-BUKTO', '05' => 'CH\'UNGCH\'ONG-BUKTO', '06' => 'KANGWON-DO', '10' => 'PUSAN-JIKHALSI', '11' => 'SEOUL-T\'UKPYOLSI', '12' => 'INCH\'ON-JIKHALSI', '13' => 'KYONGGI-DO', '14' => 'KYONGSANG-BUKTO', '15' => 'TAEGU-JIKHALSI', '16' => 'CHOLLA-NAMDO', '17' => 'CH\'UNGCH\'ONG-NAMDO', '18' => 'KWANGJU-JIKHALSI', '19' => 'TAEJON-JIKHALSI', '20' => 'KYONGSANG-NAMDO', '21' => 'ULSAN-GWANGYOKSI',
			],
			'KW' => [
				'02' => 'AL ASIMAH', '01' => 'AL AHMADI', '05' => 'AL JAHRA', '07' => 'AL FARWANIYAH', '08' => 'HAWALLI', '09' => 'MUBARAK AL KABIR',
			],
			'KY' => [
				'00' => 'CAYMAN ISLANDS',
			],
			'KZ' => [
				'01' => 'ALMATY', '02' => 'ALMATY CITY', '03' => 'AQMOLA', '04' => 'AQTOBE', '05' => 'ASTANA', '06' => 'ATYRAU', '07' => 'WEST KAZAKHSTAN', '08' => 'BAYQONYR', '09' => 'MANGGHYSTAU', '10' => 'SOUTH KAZAKHSTAN', '11' => 'PAVLODAR', '12' => 'QARAGHANDY', '13' => 'QOSTANAY', '14' => 'QYZYLORDA', '15' => 'EAST KAZAKHSTAN', '16' => 'NORTH KAZAKHSTAN', '17' => 'ZHAMBYL',
			],
			'LA' => [
				'01' => 'ATTAPU', '02' => 'CHAMPASAK', '03' => 'HOUAPHAN', '07' => 'OUDOMXAI', '13' => 'XAIGNABOURI', '14' => 'XIANGKHOANG', '15' => 'KHOUENG KHAMMOUAN', '16' => 'LOUNGNAMTHA', '17' => 'LOUANGPHRABANG', '18' => 'KHOUENG PHONGSALI', '19' => 'KHOUENG SALAVAN', '20' => 'KHOUENG SAVANNAKHET', '22' => 'BOKEO', '23' => 'BOLIKHAMXAI', '24' => 'KAMPHENG NAKHON VIANGCHAN', '26' => 'KHOUENG XEKONG', '27' => 'KHOUENG VIANGCHAN',
			],
			'LB' => [
				'04' => 'BEYROUTH', '05' => 'MONT-LIBAN', '06' => 'LIBAN-SUD', '07' => 'NABATIYE', '08' => 'BEQAA', '09' => 'LIBAN-NORD', '10' => 'AAKK', '11' => 'BAALBEK-HERMEL',
			],
			'LC' => [
				'01' => 'ANSE-LA-RAYE', '03' => 'CASTRIES', '05' => 'DENNERY', '06' => 'GROS-ISLET', '07' => 'LABORIE', '08' => 'MICOUD', '09' => 'SOUFRIERE', '10' => 'VIEUX-FORT',
			],
			'LI' => [
				'01' => 'BALZERS', '02' => 'ESCHEN', '03' => 'GAMPRIN', '04' => 'MAUREN', '05' => 'PLANKEN', '06' => 'RUGGELL', '07' => 'SCHAAN', '08' => 'SCHELLENBERG', '09' => 'TRIESEN', '10' => 'TRIESENBERG', '11' => 'VADUZ',
			],
			'LK' => [
				'29' => 'CENTRAL', '30' => 'NORTH CENTRAL', '31' => 'NORTHERN', '32' => 'NORTH WESTERN', '33' => 'SABARAGAMUWA', '34' => 'SOUTHERN', '35' => 'UVA', '36' => 'WESTERN',
			],
			'LR' => [
				'01' => 'BONG', '09' => 'NIMBA', '10' => 'SINO', '11' => 'GRAND BASSA', '12' => 'GRAND CAPE MOUNT', '13' => 'MARYLAND', '14' => 'MONTSERRADO', '15' => 'BOMI', '16' => 'GRAND KRU', '17' => 'MARGIBI', '18' => 'RIVER CESS', '19' => 'GRAND GEDEH', '20' => 'LOFA', '21' => 'GBARPOLU', '22' => 'RIVER GEE',
			],
			'LS' => [
				'10' => 'BEREA', '11' => 'BUTHA-BUTHE', '12' => 'LERIBE', '13' => 'MAFETENG', '14' => 'MASERU', '15' => 'MOHALES HOEK', '16' => 'MOKHOTLONG', '17' => 'QACHAS NEK', '18' => 'QUTHING', '19' => 'THABA-TSEKA',
			],
			'LT' => [
				'56' => 'ALYTAUS APSKRITIS', '57' => 'KAUNO APSKRITIS', '58' => 'KLAIPEDOS APSKRITIS', '59' => 'MARIJAMPOLES APSKRITIS', '60' => 'PANEVEZIO APSKRITIS', '61' => 'SIAULIU APSKRITIS', '62' => 'TAURAGES APSKRITIS', '63' => 'TELSIU APSKRITIS', '64' => 'UTENOS APSKRITIS', '65' => 'VILNIAUS APSKRITIS',
			],
			'LU' => [
				'01' => 'DIEKIRCH', '02' => 'GREVENMACHER', '03' => 'LUXEMBOURG',
			],
			'LV' => [
				'01' => 'AIZKRAUKLES', '02' => 'ALUKSNES', '03' => 'BALVU', '04' => 'BAUSKAS', '05' => 'CESU', '07' => 'DAUGAVPILS', '08' => 'DOBELES', '09' => 'GULBENES', '10' => 'JEKABPILS', '11' => 'JELGAVA', '12' => 'JELGAVAS', '13' => 'JURMALA', '14' => 'KRASLAVAS', '15' => 'KULDIGAS', '16' => 'LIEPAJA', '17' => 'LIEPAJAS', '18' => 'LIMBAZU', '19' => 'LUDZAS', '20' => 'MADONAS', '21' => 'OGRES', '22' => 'PREILU', '24' => 'REZEKNES', '25' => 'RIGA', '26' => 'RIGAS', '27' => 'SALDUS', '28' => 'TALSU', '29' => 'TUKUMA', '30' => 'VALKAS', '31' => 'VALMIERAS', '33' => 'VENTSPILS', '34' => 'ADAZU', '35' => 'AGLONAS', '37' => 'AIZPUTES', '39' => 'ALOJAS', '45' => 'BABITES', '47' => 'BALTINAVAS', '50' => 'BEVERINAS', '51' => 'BROCENU', '53' => 'CARNIKAVAS', '55' => 'CESVAINES', '56' => 'CIBLAS', '60' => 'DUNDAGAS', '67' => 'IECAVAS', '70' => 'INCUKALNA', '71' => 'JAUNJELGAVAS', '72' => 'JAUNPIEBALGAS', '73' => 'JAUNPILS', '80' => 'KEKAVAS', '82' => 'KOKNESES', '91' => 'LUBANAS', '94' => 'MALPILS', 'A2' => 'OLAINES', 'A3' => 'OZOLNIEKU', 'B4' => 'ROJAS', 'B5' => 'ROPAZU', 'B7' => 'RUGAJU', 'B9' => 'RUNDALES', 'C1' => 'SALACGRIVAS', 'C6' => 'SEJAS', 'C7' => 'SIGULDAS', 'C9' => 'SKRUNDAS', 'D2' => 'STOPINU', 'D3' => 'STRENCU', 'D7' => 'VAINODES', 'E2' => 'VARKAVAS', 'E4' => 'VECUMNIEKU',
			],
			'LY' => [
				'49' => 'AL JABAL AL AKHDAR', '05' => 'AL JUFRAH', '08' => 'AL KUFRAH', '66' => 'AL MARJ', '51' => 'AN NUQAT AL KHAMS', '53' => 'AZ ZAWIYAH', '69' => 'BENGHAZI', '55' => 'DARNAH', '71' => 'GHAT', '58' => 'MISRATAH', '30' => 'MURZUQ', '74' => 'NALUT', '34' => 'SABHA', '60' => 'SURT', '77' => 'TRIPOLI', '78' => 'WADI ASH SHATI\'', '79' => 'AL BUTNAN', '80' => 'AL JABAL AL GHARBI', '81' => 'AL JIFARAH', '82' => 'AL MARQAB', '83' => 'AL WAHAT', '84' => 'WADI AL HAYAT',
			],
			'MA' => [
				'45' => 'GRAND CASABLANCA', '46' => 'FES-BOULEMANE', '47' => 'MARRAKECH-TENSIFT-AL HAOUZ', '48' => 'MEKNES-TAFILALET', '49' => 'RABAT-SALE-ZEMMOUR-ZAER', '50' => 'CHAOUIA-OUARDIGHA', '51' => 'DOUKKALA-ABDA', '52' => 'GHARB-CHRARDA-BENI HSSEN', '53' => 'GUELMIM-ES SMARA', '54' => 'ORIENTAL', '55' => 'SOUSS-MASSA-DR', '56' => 'TADLA-AZILAL', '57' => 'TANGER-TETOUAN', '58' => 'TAZA-AL HOCEIMA-TAOUNATE',
			],
			'MC' => [
				'02' => 'MONACO',
			],
			'MD' => [
				'51' => 'GAGAUZIA', '57' => 'CHISINAU', '58' => 'STINGA NISTRULUI', '59' => 'ANENII NOI', '60' => 'BALTI', '61' => 'BASARABEASCA', '62' => 'BENDER', '63' => 'BRICENI', '64' => 'CAHUL', '65' => 'CANTEMIR', '66' => 'CALARASI', '67' => 'CAUSENI', '68' => 'CIMISLIA', '69' => 'CRIULENI', '70' => 'DONDUSENI', '71' => 'DROCHIA', '72' => 'DUBASARI', '73' => 'EDINET', '74' => 'FALESTI', '75' => 'FLORESTI', '76' => 'GLODENI', '77' => 'HINCESTI', '78' => 'IALOVENI', '79' => 'LEOVA', '80' => 'NISPORENI', '81' => 'OCNITA', '82' => 'ORHEI', '83' => 'REZINA', '84' => 'RISCANI', '85' => 'SINGEREI', '86' => 'SOLDANESTI', '87' => 'SOROCA', '88' => 'STEFAN-VODA', '89' => 'STRASENI', '90' => 'TARACLIA', '91' => 'TELENESTI', '92' => 'UNGHENI',
			],
			'ME' => [
				'02' => 'OPSTINA BAR', '05' => 'OPSTINA BUDVA', '06' => 'OPSTINA CETINJE', '07' => 'OPSTINA DANILOVGRAD', '08' => 'OPSTINA HERCEG NOVI', '09' => 'OPSTINA KOLASIN', '10' => 'OPSTINA KOTOR', '11' => 'OPSTINA MOJKOVAC', '12' => 'OPSTINA NIKSIC', '16' => 'OPSTINA PODGORICA', '19' => 'OPSTINA TIVAT', '20' => 'OPSTINA ULCINJ', '21' => 'OPSTINA ZABLJAK',
			],
			'MF' => [
				'00' => 'SAINT MARTIN',
			],
			'MG' => [
				'01' => 'ANTSIRANANA', '02' => 'FIANARANTSOA', '03' => 'MAHAJANGA', '04' => 'TOAMASINA', '05' => 'ANTANANARIVO', '06' => 'TOLIARA',
			],
			'MH' => [
				'01' => 'AILINGLAPLAP ATOLL', '03' => 'AILUK ATOLL', '04' => 'ARNO ATOLL', '05' => 'AUR ATOLL', '08' => 'EBON ATOLL', '09' => 'ENEWETAK ATOLL', '11' => 'JABAT ISLAND', '12' => 'JALUIT ATOLL', '14' => 'KILI ISLAND', '15' => 'KWAJALEIN ATOLL', '16' => 'LAE ATOLL', '17' => 'LIB ISLAND', '18' => 'LIKIEP ATOLL', '19' => 'MAJURO ATOLL', '30' => 'MALOELAP ATOLL', '31' => 'MEJIT ISLAND', '32' => 'MILI ATOLL', '33' => 'NAMDRIK ATOLL', '34' => 'NAMU ATOLL', '35' => 'RONGELAP ATOLL', '39' => 'UJAE ATOLL', '41' => 'UTRIK ATOLL', '42' => 'WOTHO ATOLL', '43' => 'WOTJE ATOLL',
			],
			'MK' => [
				'01' => 'ARACINOVO', '03' => 'BELCISTA', '04' => 'BEROVO', '05' => 'BISTRICA', '06' => 'BITOLA', '07' => 'BLATEC', '08' => 'BOGDANCI', '09' => 'BOGOMILA', '10' => 'BOGOVINJE', '11' => 'BOSILOVO', '12' => 'BRVENICA', '14' => 'CAPARI', '15' => 'CASKA', '16' => 'CEGRANE', '17' => 'CENTAR', '18' => 'CENTAR ZUPA', '19' => 'CESINOVO', '20' => 'CUCER-SANDEVO', '21' => 'DEBAR', '22' => 'DELCEVO', '23' => 'DELOGOZDI', '24' => 'DEMIR HISAR', '25' => 'DEMIR KAPIJA', '26' => 'DOBRUSEVO', '27' => 'DOLNA BANJICA', '28' => 'DOLNENI', '30' => 'DRUGOVO', '31' => 'DZEPCISTE', '32' => 'GAZI BABA', '33' => 'GEVGELIJA', '34' => 'GOSTIVAR', '35' => 'GRADSKO', '36' => 'ILINDEN', '38' => 'JEGUNOVCE', '39' => 'KAMENJANE', '40' => 'KARBINCI', '41' => 'KARPOS', '42' => 'KAVADARCI', '43' => 'KICEVO', '44' => 'KISELA VODA', '45' => 'KLECEVCE', '46' => 'KOCANI', '47' => 'KONCE', '48' => 'KONDOVO', '50' => 'KOSEL', '51' => 'KRATOVO', '52' => 'KRIVA PALANKA', '53' => 'KRIVOGASTANI', '54' => 'KRUSEVO', '55' => 'KUKLIS', '56' => 'KUKURECANI', '57' => 'KUMANOVO', '58' => 'LABUNISTA', '59' => 'LIPKOVO', '60' => 'LOZOVO', '61' => 'LUKOVO', '62' => 'MAKEDONSKA KAMENICA', '63' => 'MAKEDONSKI BROD', '65' => 'MESEISTA', '66' => 'MIRAVCI', '67' => 'MOGILA', '68' => 'MURTINO', '69' => 'NEGOTINO', '70' => 'NEGOTINO-POLOSKO', '71' => 'NOVACI', '72' => 'NOVO SELO', '73' => 'OBLESEVO', '74' => 'OHRID', '75' => 'ORASAC', '76' => 'ORIZARI', '77' => 'OSLOMEJ', '78' => 'PEHCEVO', '79' => 'PETROVEC', '80' => 'PLASNICA', '81' => 'PODARES', '82' => 'PRILEP', '83' => 'PROBISTIP', '84' => 'RADOVIS', '85' => 'RANKOVCE', '86' => 'RESEN', '87' => 'ROSOMAN', '88' => 'ROSTUSA', '89' => 'SAMOKOV', '90' => 'SARAJ', '91' => 'SIPKOVICA', '92' => 'SOPISTE', '93' => 'SOPOTNICA', '94' => 'SRBINOVO', '96' => 'STAR DOJRAN', '97' => 'STARO NAGORICANE', '98' => 'STIP', '99' => 'STRUGA', 'A1' => 'STRUMICA', 'A2' => 'STUDENICANI', 'A3' => 'SUTO ORIZARI', 'A4' => 'SVETI NIKOLE', 'A5' => 'TEARCE', 'A6' => 'TETOVO', 'A7' => 'TOPOLCANI', 'A8' => 'VALANDOVO', 'A9' => 'VASILEVO', 'B1' => 'VELES', 'B2' => 'VELESTA', 'B3' => 'VEVCANI', 'B4' => 'VINICA', 'B6' => 'VRANESTICA', 'B7' => 'VRAPCISTE', 'B8' => 'VRATNICA', 'B9' => 'VRUTOK', 'C1' => 'ZAJAS', 'C2' => 'ZELENIKOVO', 'C3' => 'ZELINO', 'C4' => 'ZITOSE', 'C5' => 'ZLETOVO', 'C6' => 'ZRNOVCI',
			],
			'ML' => [
				'01' => 'BAMAKO', '03' => 'KAYES', '04' => 'MOPTI', '05' => 'SEGOU', '06' => 'SIKASSO', '07' => 'KOULIKORO', '08' => 'TOMBOUCTOU', '09' => 'GAO', '10' => 'KIDAL',
			],
			'MM' => [
				'01' => 'RAKHINE STATE', '02' => 'CHIN STATE', '03' => 'IRRAWADDY', '04' => 'KACHIN STATE', '05' => 'KARAN STATE', '06' => 'KAYAH STATE', '07' => 'MAGWE', '08' => 'MANDALAY', '09' => 'PEGU', '10' => 'SAGAING', '11' => 'SHAN STATE', '12' => 'TENASSERIM', '13' => 'MON STATE', '17' => 'YANGON',
			],
			'MN' => [
				'01' => 'ARHANGAY', '02' => 'BAYANHONGOR', '03' => 'BAYAN-OLGIY', '06' => 'DORNOD', '07' => 'DORNOGOVI', '08' => 'DUNDGOVI', '09' => 'DZAVHAN', '10' => 'GOVI-ALTAY', '11' => 'HENTIY', '12' => 'HOVD', '13' => 'HOVSGOL', '14' => 'OMNOGOVI', '15' => 'OVORHANGAY', '16' => 'SELENGE', '17' => 'SUHBAATAR', '18' => 'TOV', '19' => 'UVS', '20' => 'ULAANBAATAR', '21' => 'BULGAN', '23' => 'DARHAN-UUL', '24' => 'GOVISUMBER', '25' => 'ORHON',
			],
			'MO' => [
				'02' => 'MACAU',
			],
			'MP' => [
				'00' => 'NORTHERN MARIANA ISLANDS', '85' => 'NORTHERN MARIANA ISLANDS', '11' => 'NORTHERN MARIANA ISLANDS', '12' => 'NORTHERN MARIANA ISLANDS',
			],
			'MQ' => [
				'MQ' => 'MARTINIQUE',
			],
			'MR' => [
				'01' => 'HODH ECH CHARGUI', '02' => 'HODH EL GHARBI', '03' => 'ASSABA', '04' => 'GORGOL', '05' => 'BRAKNA', '06' => 'TRARZA', '07' => 'ADRAR', '08' => 'DAKHLET NOUADHIBOU', '09' => 'TAGANT', '10' => 'GUIDIMAKA', '11' => 'TIRIS ZEMMOUR', '12' => 'INCHIRI', '13' => 'NOUAKCHOTT',
			],
			'MS' => [
				'01' => 'SAINT ANTHONY', '03' => 'SAINT PETER',
			],
			'MT' => [
				'00' => 'MALTA',
			],
			'MU' => [
				'00' => 'MAURITIUS', '12' => 'BLACK RIVER', '13' => 'FLACQ', '14' => 'GRAND PORT', '15' => 'MOKA', '16' => 'PAMPLEMOUSSES', '17' => 'PLAINES WILHEMS', '18' => 'PORT LOUIS', '19' => 'RIVIERE DU REMPART', '20' => 'SAVANNE',
			],
			'MV' => [
				'01' => 'SEENU', '05' => 'LAAMU', '30' => 'ALIFU', '31' => 'BAA', '32' => 'DHAALU', '35' => 'GAAFU DHAALU', '36' => 'HAA ALIFU', '37' => 'HAA DHAALU', '38' => 'KAAFU', '40' => 'MAALE', '41' => 'MEEMU', '43' => 'NOONU', '44' => 'RAA', '45' => 'SHAVIYANI', '46' => 'THAA',
			],
			'MW' => [
				'02' => 'CHIKWAWA', '03' => 'CHIRADZULU', '04' => 'CHITIPA', '05' => 'THYOLO', '06' => 'DEDZA', '07' => 'DOWA', '08' => 'KARONGA', '09' => 'KASUNGU', '11' => 'LILONGWE', '12' => 'MANGOCHI', '13' => 'MCHINJI', '15' => 'MZIMBA', '16' => 'NTCHEU', '17' => 'NKHATA BAY', '18' => 'NKHOTAKOTA', '19' => 'NSANJE', '20' => 'NTCHISI', '21' => 'RUMPHI', '22' => 'SALIMA', '23' => 'ZOMBA', '24' => 'BLANTYRE', '25' => 'MWANZA', '26' => 'BALAKA', '27' => 'LIKOMA', '28' => 'MACHINGA', '29' => 'MULANJE', '30' => 'PHALOMBE', '31' => 'NENO',
			],
			'MX' => [
				'01' => 'AGUASCALIENTES', '02' => 'BAJA CALIFORNIA', '03' => 'BAJA CALIFORNIA SUR', '04' => 'CAMPECHE', '05' => 'CHIAPAS', '06' => 'CHIHUAHUA', '07' => 'COAHUILA DE ZARAGOZA', '08' => 'COLIMA', '09' => 'DISTRITO FEDERAL', '10' => 'DURANGO', '11' => 'GUANAJUATO', '12' => 'GUERRERO', '13' => 'HIDALGO', '14' => 'JALISCO', '15' => 'MEXICO', '16' => 'MICHOACAN DE OCAMPO', '17' => 'MORELOS', '18' => 'NAYARIT', '19' => 'NUEVO LEON', '20' => 'OAXACA', '21' => 'PUEBLA', '22' => 'QUERETARO DE ARTEAGA', '23' => 'QUINTANA ROO', '24' => 'SAN LUIS POTOSI', '25' => 'SINALOA', '26' => 'SONORA', '27' => 'TABASCO', '28' => 'TAMAULIPAS', '29' => 'TLAXCALA', '30' => 'VERACRUZ-LLAVE', '31' => 'YUCATAN', '32' => 'ZACATECAS',
			],
			'MY' => [
				'01' => 'JOHOR', '02' => 'KEDAH', '03' => 'KELANTAN', '04' => 'MELAKA', '05' => 'NEGERI SEMBILAN', '06' => 'PAHANG', '07' => 'PERAK', '08' => 'PERLIS', '09' => 'PULAU PINANG', '11' => 'SARAWAK', '12' => 'SELANGOR', '13' => 'TERENGGANU', '14' => 'KUALA LUMPUR', '15' => 'LABUAN', '16' => 'SABAH', '17' => 'PUTRAJAYA',
			],
			'MZ' => [
				'01' => 'CABO DELGADO', '02' => 'GAZA', '03' => 'INHAMBANE', '04' => 'MAPUTO', '05' => 'SOFALA', '06' => 'NAMPULA', '07' => 'NIASSA', '08' => 'TETE', '09' => 'ZAMBEZIA', '10' => 'MANICA', '11' => 'MAPUTO',
			],
			'NA' => [
				'06' => 'KAOKOLAND', '13' => 'OTJIWARONGO', '21' => 'WINDHOEK', '28' => 'CAPRIVI', '29' => 'ERONGO', '30' => 'HARDAP', '31' => 'KARAS', '32' => 'KUNENE', '33' => 'OHANGWENA', '34' => 'OKAVANGO', '35' => 'OMAHEKE', '36' => 'OMUSATI', '37' => 'OSHANA', '38' => 'OSHIKOTO', '39' => 'OTJOZONDJUPA',
			],
			'NC' => [
				'01' => 'PROVINCE NORD', '02' => 'PROVINCE SUD', '03' => 'PROVINCE DES ILES LOYAUTE',
			],
			'NE' => [
				'01' => 'AGADEZ', '02' => 'DIFFA', '03' => 'DOSSO', '04' => 'MARADI', '06' => 'TAHOUA', '07' => 'ZINDER', '08' => 'NIAMEY', '09' => 'TILLABERI',
			],
			'NF' => [
				'00' => 'NORFOLK ISLAND',
			],
			'NG' => [
				'05' => 'LAGOS', '11' => 'FEDERAL CAPITAL TERRITORY', '16' => 'OGUN', '21' => 'AKWA IBOM', '22' => 'CROSS RIVER', '23' => 'KADUNA', '24' => 'KATSINA', '25' => 'ANAMBRA', '26' => 'BENUE', '27' => 'BORNO', '28' => 'IMO', '29' => 'KANO', '30' => 'KWARA', '31' => 'NIGER', '32' => 'OYO', '35' => 'ADAMAWA', '36' => 'DELTA', '37' => 'EDO', '39' => 'JIGAWA', '40' => 'KEBBI', '41' => 'KOGI', '42' => 'OSUN', '43' => 'TARABA', '44' => 'YOBE', '45' => 'ABIA', '46' => 'BAUCHI', '47' => 'ENUGU', '48' => 'ONDO', '49' => 'PLATEAU', '50' => 'RIVERS', '51' => 'SOKOTO', '52' => 'BAYELSA', '53' => 'EBONYI', '54' => 'EKITI', '55' => 'GOMBE', '56' => 'NASSARAWA', '57' => 'ZAMFARA',
			],
			'NI' => [
				'01' => 'BOACO', '02' => 'CARAZO', '03' => 'CHINANDEGA', '04' => 'CHONTALES', '05' => 'ESTELI', '06' => 'GRANADA', '07' => 'JINOTEGA', '08' => 'LEON', '09' => 'MADRIZ', '10' => 'MANAGUA', '11' => 'MASAYA', '12' => 'MATAGALPA', '13' => 'NUEVA SEGOVIA', '14' => 'RIO SAN JUAN', '15' => 'RIVAS', '17' => 'AUTONOMA ATLANTICO NORTE', '18' => 'REGION AUTONOMA ATLANTICO SUR',
			],
			'NL' => [
				'01' => 'DRENTHE', '02' => 'FRIESLAND', '03' => 'GELDERLAND', '04' => 'GRONINGEN', '05' => 'LIMBURG', '06' => 'NOORD-BRABANT', '07' => 'NOORD-HOLLAND', '09' => 'UTRECHT', '10' => 'ZEELAND', '11' => 'ZUID-HOLLAND', '15' => 'OVERIJSSEL', '16' => 'FLEVOLAND',
			],
			'NO' => [
				'01' => 'AKERSHUS', '02' => 'AUST-AGDER', '04' => 'BUSKERUD', '05' => 'FINNMARK', '06' => 'HEDMARK', '07' => 'HORDALAND', '08' => 'MORE OG ROMSDAL', '09' => 'NORDLAND', '10' => 'NORD-TRONDELAG', '11' => 'OPPLAND', '12' => 'OSLO', '13' => 'OSTFOLD', '14' => 'ROGALAND', '15' => 'SOGN OG FJORDANE', '16' => 'SOR-TRONDELAG', '17' => 'TELEMARK', '18' => 'TROMS', '19' => 'VEST-AGDER', '20' => 'VESTFOLD',
			],
			'NP' => [
				'01' => 'BAGMATI', '02' => 'BHERI', '03' => 'DHAWALAGIRI', '04' => 'GANDAKI', '05' => 'JANAKPUR', '06' => 'KARNALI', '07' => 'KOSI', '08' => 'LUMBINI', '09' => 'MAHAKALI', '10' => 'MECHI', '11' => 'NARAYANI', '12' => 'RAPTI', '13' => 'SAGARMATHA', '14' => 'SETI',
			],
			'NR' => [
				'14' => 'YAREN',
			],
			'NU' => [
				'00' => 'NIUE',
			],
			'NZ' => [
				'10' => 'CHATHAM ISLANDS', 'E7' => 'AUCKLAND', 'E8' => 'BAY OF PLENTY', 'E9' => 'CANTERBURY', 'F1' => 'GISBORNE', 'F2' => 'HAWKE\'S BAY', 'F3' => 'MANAWATU-WANGANUI', 'F4' => 'MARLBOROUGH', 'F5' => 'NELSON', 'F6' => 'NORTHLAND', 'F7' => 'OTAGO', 'F8' => 'SOUTHLAND', 'F9' => 'TARANAKI', 'G1' => 'WAIKATO', 'G2' => 'WELLINGTON', 'G3' => 'WEST COAST', 'T1' => 'TASMAN',
			],
			'OM' => [
				'01' => 'AD DAKHILIYAH', '02' => 'AL BATINAH', '03' => 'AL WUSTA', '04' => 'ASH SHARQIYAH', '05' => 'AZ ZAHIRAH', '06' => 'MASQAT', '07' => 'MUSANDAM', '08' => 'ZUFAR', '09' => 'AD DHAHIRAH', '10' => 'AL BURAYMI',
			],
			'PA' => [
				'01' => 'BOCAS DEL TORO', '02' => 'CHIRIQUI', '03' => 'COCLE', '04' => 'COLON', '05' => 'DARIEN', '06' => 'HERRERA', '07' => 'LOS SANTOS', '08' => 'PANAMA', '09' => 'SAN BLAS', '10' => 'VERAGUAS',
			],
			'PE' => [
				'01' => 'AMAZONAS', '02' => 'ANCASH', '03' => 'APURIMAC', '04' => 'AREQUIPA', '05' => 'AYACUCHO', '06' => 'CAJAMARCA', '07' => 'CALLAO', '08' => 'CUSCO', '09' => 'HUANCAVELICA', '10' => 'HUANUCO', '11' => 'ICA', '12' => 'JUNIN', '13' => 'LA LIBERTAD', '14' => 'LAMBAYEQUE', '15' => 'LIMA', '16' => 'LORETO', '17' => 'MADRE DE DIOS', '18' => 'MOQUEGUA', '19' => 'PASCO', '20' => 'PIURA', '21' => 'PUNO', '22' => 'SAN MARTIN', '23' => 'TACNA', '24' => 'TUMBES', '25' => 'UCAYALI', '26' => 'PROVINCIA DE LIMA',
			],
			'PF' => [
				'01' => 'ILES DU VENT', '02' => 'ILES SOUS-LE-VENT', '03' => 'ILES TUAMOTU-GAMBIER', '04' => 'ILES MARQUISES', '05' => 'ILES AUSTRALES',
			],
			'PG' => [
				'02' => 'GULF', '03' => 'MILNE BAY', '04' => 'NORTHERN', '05' => 'SOUTHERN HIGHLANDS', '06' => 'WESTERN', '07' => 'NORTH SOLOMONS', '08' => 'CHIMBU', '09' => 'EASTERN HIGHLANDS', '10' => 'EAST NEW BRITAIN', '11' => 'EAST SEPIK', '12' => 'MADANG', '13' => 'MANUS', '14' => 'MOROBE', '15' => 'NEW IRELAND', '16' => 'WESTERN HIGHLANDS', '17' => 'WEST NEW BRITAIN', '18' => 'SANDAUN', '19' => 'ENGA', '20' => 'NATIONAL CAPITAL',
			],
			'PH' => [
				'01' => 'ABRA', '02' => 'AGUSAN DEL NORTE', '03' => 'AGUSAN DEL SUR', '04' => 'AKLAN', '05' => 'ALBAY', '06' => 'ANTIQUE', '07' => 'BATAAN', '08' => 'BATANES', '09' => 'BATANGAS', '10' => 'BENGUET', '11' => 'BOHOL', '12' => 'BUKIDNON', '13' => 'BULACAN', '14' => 'CAGAYAN', '15' => 'CAMARINES NORTE', '16' => 'CAMARINES SUR', '17' => 'CAMIGUIN', '18' => 'CAPIZ', '19' => 'CATANDUANES', '20' => 'CAVITE', '21' => 'CEBU', '22' => 'BASILAN', '23' => 'EASTERN SAMAR', '24' => 'DAVAO', '25' => 'DAVAO DEL SUR', '26' => 'DAVAO ORIENTAL', '27' => 'IFUGAO', '28' => 'ILOCOS NORTE', '29' => 'ILOCOS SUR', '30' => 'ILOILO', '31' => 'ISABELA', '32' => 'KALINGA-APAYAO', '33' => 'LAGUNA', '34' => 'LANAO DEL NORTE', '35' => 'LANAO DEL SUR', '36' => 'LA UNION', '37' => 'LEYTE', '38' => 'MARINDUQUE', '39' => 'MASBATE', '40' => 'MINDORO OCCIDENTAL', '41' => 'MINDORO ORIENTAL', '42' => 'MISAMIS OCCIDENTAL', '43' => 'MISAMIS ORIENTAL', '44' => 'MOUNTAIN', '46' => 'NEGROS ORIENTAL', '47' => 'NUEVA ECIJA', '48' => 'NUEVA VIZCAYA', '49' => 'PALAWAN', '50' => 'PAMPANGA', '51' => 'PANGASINAN', '53' => 'RIZAL', '54' => 'ROMBLON', '55' => 'SAMAR', '56' => 'MAGUINDANAO', '57' => 'NORTH COTABATO', '58' => 'SORSOGON', '59' => 'SOUTHERN LEYTE', '60' => 'SULU', '61' => 'SURIGAO DEL NORTE', '62' => 'SURIGAO DEL SUR', '63' => 'TARLAC', '64' => 'ZAMBALES', '65' => 'ZAMBOANGA DEL NORTE', '66' => 'ZAMBOANGA DEL SUR', '67' => 'NORTHERN SAMAR', '68' => 'QUIRINO', '69' => 'SIQUIJOR', '70' => 'SOUTH COTABATO', '71' => 'SULTAN KUDARAT', '72' => 'TAWITAWI', 'A1' => 'ANGELES', 'A2' => 'BACOLOD', 'A4' => 'BAGUIO', 'A7' => 'BATANGAS CITY', 'A8' => 'BUTUAN', 'A9' => 'CABANATUAN', 'B1' => 'CADIZ', 'B2' => 'CAGAYAN DE ORO', 'B3' => 'CALBAYOG', 'B4' => 'CALOOCAN', 'B5' => 'CANLAON', 'B6' => 'CAVITE CITY', 'B8' => 'COTABATO', 'C1' => 'DANAO', 'C2' => 'DAPITAN', 'C3' => 'DAVAO CITY', 'C4' => 'DIPOLOG', 'C5' => 'DUMAGUETE', 'C6' => 'GENERAL SANTOS', 'C7' => 'GINGOOG', 'C8' => 'ILIGAN', 'C9' => 'ILOILO CITY', 'D1' => 'IRIGA', 'D2' => 'LA CARLOTA', 'D3' => 'LAOAG', 'D4' => 'LAPU-LAPU', 'D5' => 'LEGASPI', 'D6' => 'LIPA', 'D7' => 'LUCENA', 'D8' => 'MANDAUE', 'D9' => 'MANILA', 'E1' => 'MARAWI', 'E2' => 'NAGA', 'E3' => 'OLONGAPO', 'E4' => 'ORMOC', 'E5' => 'OROQUIETA', 'E6' => 'OZAMIS', 'E7' => 'PAGADIAN', 'E8' => 'PALAYAN', 'F1' => 'PUERTO PRINCESA', 'F3' => 'ROXAS', 'F4' => 'SAN CARLOS', 'F7' => 'SAN PABLO', 'F8' => 'SILAY', 'F9' => 'SURIGAO', 'G1' => 'TACLOBAN', 'G2' => 'TAGAYTAY', 'G3' => 'TAGBILARAN', 'G4' => 'TANGUB', 'G5' => 'TOLEDO', 'G6' => 'TRECE MARTIRES', 'G7' => 'ZAMBOANGA', 'G8' => 'AURORA', 'H2' => 'QUEZON', 'H3' => 'NEGROS OCCIDENTAL',
			],
			'PK' => [
				'01' => 'FEDERALLY ADMINISTERED TRIBAL AREAS', '02' => 'BALOCHISTAN', '03' => 'NORTH-WEST FRONTIER', '04' => 'PUNJAB', '05' => 'SINDH', '06' => 'AZAD KASHMIR', '07' => 'NORTHERN AREAS', '08' => 'ISLAMABAD',
			],
			'PL' => [
				'72' => 'DOLNOSLASKIE', '73' => 'KUJAWSKO-POMORSKIE', '74' => 'LODZKIE', '75' => 'LUBELSKIE', '76' => 'LUBUSKIE', '77' => 'MALOPOLSKIE', '78' => 'MAZOWIECKIE', '79' => 'OPOLSKIE', '80' => 'PODKARPACKIE', '81' => 'PODLASKIE', '82' => 'POMORSKIE', '83' => 'SLASKIE', '84' => 'SWIETOKRZYSKIE', '85' => 'WARMINSKO-MAZURSKIE', '86' => 'WIELKOPOLSKIE', '87' => 'ZACHODNIOPOMORSKIE',
			],
			'PM' => [
				'01' => 'SAINT PIERRE AND MIQUELON', '02' => 'SAINT PIERRE AND MIQUELON',
			],
			'PN' => [
				'00' => 'PITCAIRN ISLANDS',
			],
			'PR' => [
				'01' => 'ADJUNTAS', '03' => 'AGUADA', '05' => 'AGUADILLA', '07' => 'AGUAS BUENAS', '09' => 'AIBONITO', '11' => 'ANASCO', '13' => 'ARECIBO', '15' => 'ARROYO', '17' => 'BARCELONETA', '19' => 'BARRANQUITAS', '21' => 'BAYAMON', '23' => 'CABO ROJO', '25' => 'CAGUAS', '27' => 'CAMUY', '29' => 'CANOVANAS', '31' => 'CAROLINA', '33' => 'CATANO', '35' => 'CAYEY', '37' => 'CEIBA', '39' => 'CIALES', '41' => 'CIDRA', '43' => 'COAMO', '45' => 'COMERIO', '47' => 'COROZAL', '49' => 'CULEBRA', '51' => 'DORADO', '53' => 'FAJARDO', '54' => 'FLORIDA', '55' => 'GUANICA', '57' => 'GUAYAMA', '59' => 'GUAYANILLA', '61' => 'GUAYNABO', '63' => 'GURABO', '65' => 'HATILLO', '67' => 'HORMIGUEROS', '69' => 'HUMACAO', '71' => 'ISABELA', '73' => 'MUNICIPIO DE JAYUYA', '75' => 'JUANA DIAZ', '77' => 'MUNICIPIO DE JUNCOS', '79' => 'LAJAS', '81' => 'LARES', '83' => 'LAS MARIAS', '85' => 'LAS PIEDRAS', '87' => 'LOIZA', '89' => 'LUQUILLO', '91' => 'MANATI', '93' => 'MARICAO', '95' => 'MAUNABO', '97' => 'MAYAGUEZ', '99' => 'MOCA', 'A1' => 'MOROVIS', 'A3' => 'NAGUABO', 'A5' => 'NARANJITO', 'A9' => 'PATILLAS', 'B1' => 'PENUELAS', 'B3' => 'PONCE', 'B5' => 'QUEBRADILLAS', 'B7' => 'RINCON', 'B9' => 'RIO GRANDE', 'C1' => 'SABANA GRANDE', 'C3' => 'SALINAS', 'C5' => 'SAN GERMAN', 'C7' => 'SAN JUAN', 'C9' => 'SAN LORENZO', 'D1' => 'SAN SEBASTIAN', 'D3' => 'SANTA ISABEL MUNICIPIO', 'D5' => 'TOA ALTA', 'D7' => 'TOA BAJA', 'D9' => 'TRUJILLO ALTO', 'E1' => 'UTUADO', 'E3' => 'VEGA ALTA', 'E5' => 'VEGA BAJA', 'E7' => 'VIEQUES', 'E9' => 'VILLALBA', 'F1' => 'YABUCOA', 'F3' => 'YAUCO',
			],
			'PS' => [
				'GZ' => 'GAZA', 'WE' => 'WEST BANK',
			],
			'PT' => [
				'02' => 'AVEIRO', '03' => 'BEJA', '04' => 'BRAGA', '05' => 'BRAGANCA', '06' => 'CASTELO BRANCO', '07' => 'COIMBRA', '08' => 'EVORA', '09' => 'FARO', '10' => 'MADEIRA', '11' => 'GUARDA', '13' => 'LEIRIA', '14' => 'LISBOA', '16' => 'PORTALEGRE', '17' => 'PORTO', '18' => 'SANTAREM', '19' => 'SETUBAL', '20' => 'VIANA DO CASTELO', '21' => 'VILA REAL', '22' => 'VISEU', '23' => 'AZORES',
			],
			'PW' => [
				'01' => 'AIMELIIK', '02' => 'AIRAI', '03' => 'ANGAUR', '05' => 'KAYANGEL', '06' => 'KOROR', '07' => 'MELEKEOK', '08' => 'NGARAARD', '09' => 'NGARCHELONG', '10' => 'NGARDMAU', '11' => 'NGATPANG', '14' => 'NGIWAL', '15' => 'PELELIU',
			],
			'PY' => [
				'01' => 'ALTO PARANA', '02' => 'AMAMBAY', '04' => 'CAAGUAZU', '05' => 'CAAZAPA', '06' => 'CENTRAL', '07' => 'CONCEPCION', '08' => 'CORDILLERA', '10' => 'GUAIRA', '11' => 'ITAPUA', '12' => 'MISIONES', '13' => 'NEEMBUCU', '15' => 'PARAGUARI', '16' => 'PRESIDENTE HAYES', '17' => 'SAN PEDRO', '19' => 'CANINDEYU', '22' => 'ASUNCION', '23' => 'ALTO PARAGUAY', '03' => 'BOQUERON',
			],
			'QA' => [
				'01' => 'AD DAWHAH', '04' => 'AL KHAWR', '06' => 'AR RAYYAN', '08' => 'MADINAT ACH SHAMAL', '09' => 'UMM SALAL', '10' => 'AL WAKRAH', '13' => 'AZ ZA\'AYIN',
			],
			'RE' => [
				'RE' => 'REUNION',
			],
			'RO' => [
				'01' => 'ALBA', '02' => 'ARAD', '03' => 'ARGES', '04' => 'BACAU', '05' => 'BIHOR', '06' => 'BISTRITA-NASAUD', '07' => 'BOTOSANI', '08' => 'BRAILA', '09' => 'BRASOV', '10' => 'BUCURESTI', '11' => 'BUZAU', '12' => 'CARAS-SEVERIN', '13' => 'CLUJ', '14' => 'CONSTANTA', '15' => 'COVASNA', '16' => 'DAMBOVITA', '17' => 'DOLJ', '18' => 'GALATI', '19' => 'GORJ', '20' => 'HARGHITA', '21' => 'HUNEDOARA', '22' => 'IALOMITA', '23' => 'IASI', '25' => 'MARAMURES', '26' => 'MEHEDINTI', '27' => 'MURES', '28' => 'NEAMT', '29' => 'OLT', '30' => 'PRAHOVA', '31' => 'SALAJ', '32' => 'SATU MARE', '33' => 'SIBIU', '34' => 'SUCEAVA', '35' => 'TELEORMAN', '36' => 'TIMIS', '37' => 'TULCEA', '38' => 'VASLUI', '39' => 'VALCEA', '40' => 'VRANCEA', '41' => 'CALARASI', '42' => 'GIURGIU', '43' => 'ILFOV',
			],
			'RS' => [
				'00' => 'CENTRAL SERBIA', '01' => 'KOSOVO', '02' => 'VOJVODINA',
			],
			'RU' => [
				'01' => 'ADYGEYA', '03' => 'GORNO-ALTAY', '04' => 'ALTAISKY KRAI', '05' => 'AMUR', '06' => 'ARKHANGELSK', '07' => 'ASTRAKHAN\'', '08' => 'BASHKORTOSTAN', '09' => 'BELGOROD', '10' => 'BRYANSK', '11' => 'BURYAT', '12' => 'CHECHNYA', '13' => 'CHELYABINSK', '15' => 'CHUKOT', '16' => 'CHUVASHIA', '17' => 'DAGESTAN', '19' => 'INGUSH', '20' => 'IRKUTSK', '21' => 'IVANOVO', '22' => 'KABARDIN-BALKAR', '23' => 'KALININGRAD', '24' => 'KALMYK', '25' => 'KALUGA', '27' => 'KARACHAY-CHERKESS', '28' => 'KARELIA', '29' => 'KEMEROVO', '30' => 'KHABAROVSK', '31' => 'KHAKASS', '32' => 'KHANTY-MANSIY', '33' => 'KIROV', '34' => 'KOMI', '37' => 'KOSTROMA', '38' => 'KRASNODAR', '40' => 'KURGAN', '41' => 'KURSK', '42' => 'LENINGRAD', '43' => 'LIPETSK', '44' => 'MAGADAN', '45' => 'MARIY-EL', '46' => 'MORDOVIA', '47' => 'MOSKVA', '48' => 'MOSCOW CITY', '49' => 'MURMANSK', '50' => 'NENETS', '51' => 'NIZHEGOROD', '52' => 'NOVGOROD', '53' => 'NOVOSIBIRSK', '54' => 'OMSK', '55' => 'ORENBURG', '56' => 'OREL', '57' => 'PENZA', '58' => 'PERM\'', '59' => 'PRIMOR\'YE', '60' => 'PSKOV', '61' => 'ROSTOV', '62' => 'RYAZAN\'', '63' => 'SAKHA', '64' => 'SAKHALIN', '65' => 'SAMARA', '66' => 'SAINT PETERSBURG CITY', '67' => 'SARATOV', '68' => 'NORTH OSSETIA', '69' => 'SMOLENSK', '70' => 'STAVROPOL\'', '71' => 'SVERDLOVSK', '72' => 'TAMBOVSKAYA OBLAST', '73' => 'TATARSTAN', '75' => 'TOMSK', '76' => 'TULA', '77' => 'TVER\'', '78' => 'TYUMEN\'', '79' => 'TUVA', '80' => 'UDMURT', '81' => 'UL\'YANOVSK', '83' => 'VLADIMIR', '84' => 'VOLGOGRAD', '85' => 'VOLOGDA', '86' => 'VORONEZH', '87' => 'YAMAL-NENETS', '88' => 'YAROSLAVL\'', '89' => 'YEVREY', '90' => 'PERMSKIY KRAY', '91' => 'KRASNOYARSKIY KRAY', '26' => 'KAMCHATKA', '93' => 'ZABAYKALSKY',
			],
			'RW' => [
				'11' => 'EST', '12' => 'KIGALI', '13' => 'NORD', '14' => 'OUEST', '15' => 'SUD',
			],
			'SA' => [
				'02' => 'AL BAHAH', '05' => 'AL MADINAH', '06' => 'ASH SHARQIYAH', '08' => 'AL QASIM', '10' => 'AR RIYAD', '11' => 'ASIR PROVINCE', '13' => 'HA\'IL', '14' => 'MAKKAH', '15' => 'AL HUDUD ASH SHAMALIYAH', '16' => 'NAJRAN', '17' => 'JIZAN', '19' => 'TABUK', '20' => 'AL JAWF',
			],
			'SB' => [
				'03' => 'MALAITA', '06' => 'GUADALCANAL', '07' => 'ISABEL', '08' => 'MAKIRA', '10' => 'CENTRAL', '11' => 'WESTERN',
			],
			'SC' => [
				'26' => 'ENGLISH RIVER',
			],
			'SD' => [
				'29' => 'KHARTOUM', '36' => 'RED SEA', '38' => 'GEZIRA', '39' => 'GEDARIF', '41' => 'WHITE NILE', '42' => 'BLUE NILE', '43' => 'NORTHERN', '47' => 'WEST DARFUR', '49' => 'SOUTH DARFUR', '50' => 'SOUTH KORDUFAN', '52' => 'KASSALA', '53' => 'RIVER NILE', '55' => 'NORTH DARFUR', '56' => 'NORTH KORDUFAN', '58' => 'SENNAR',
			],
			'SE' => [
				'02' => 'BLEKINGE LAN', '03' => 'GAVLEBORGS LAN', '05' => 'GOTLANDS LAN', '06' => 'HALLANDS LAN', '07' => 'JAMTLANDS LAN', '08' => 'JONKOPINGS LAN', '09' => 'KALMAR LAN', '10' => 'DALARNAS LAN', '12' => 'KRONOBERGS LAN', '14' => 'NORRBOTTENS LAN', '15' => 'OREBRO LAN', '16' => 'OSTERGOTLANDS LAN', '18' => 'SODERMANLANDS LAN', '21' => 'UPPSALA LAN', '22' => 'VARMLANDS LAN', '23' => 'VASTERBOTTENS LAN', '24' => 'VASTERNORRLANDS LAN', '25' => 'VASTMANLANDS LAN', '26' => 'STOCKHOLMS LAN', '27' => 'SKANE LAN', '28' => 'VASTRA GOTALAND',
			],
			'SG' => [
				'00' => 'SINGAPORE',
			],
			'SH' => [
				'01' => 'ASCENSION', '02' => 'SAINT HELENA', '03' => 'TRISTAN DA CUNHA',
			],
			'SI' => [
				'01' => 'AJDOVSCINA', '03' => 'BLED', '04' => 'BOHINJ', '05' => 'BOROVNICA', '06' => 'BOVEC', '08' => 'BREZICE', '09' => 'BREZOVICA', '11' => 'CELJE', '13' => 'CERKNICA', '14' => 'CERKNO', '15' => 'CRENSOVCI', '17' => 'CRNOMELJ', '19' => 'DIVACA', '25' => 'DRAVOGRAD', '29' => 'GORNJA RADGONA', '32' => 'GROSUPLJE', '34' => 'HRASTNIK', '36' => 'IDRIJA', '37' => 'IG', '38' => 'ILIRSKA BISTRICA', '39' => 'IVANCNA GORICA', '40' => 'IZOLA-ISOLA', '44' => 'KANAL', '45' => 'KIDRICEVO', '46' => 'KOBARID', '50' => 'KOPER-CAPODISTRIA', '52' => 'KRANJ', '53' => 'KRANJSKA GORA', '54' => 'KRSKO', '57' => 'LASKO', '61' => 'LJUBLJANA', '64' => 'LOGATEC', '71' => 'MEDVODE', '72' => 'MENGES', '73' => 'METLIKA', '74' => 'MEZICA', '76' => 'MISLINJA', '79' => 'MOZIRJE', '80' => 'MURSKA SOBOTA', '81' => 'MUTA', '84' => 'NOVA GORICA', '86' => 'ODRANCI', '87' => 'ORMOZ', '91' => 'PIVKA', '94' => 'POSTOJNA', '98' => 'RACAM', '99' => 'RADECE', 'A1' => 'RADENCI', 'A2' => 'RADLJE OB DRAVI', 'A3' => 'RADOVLJICA', 'A7' => 'ROGASKA SLATINA', 'B2' => 'SENCUR', 'B3' => 'SENTILJ', 'B6' => 'SEVNICA', 'B7' => 'SEZANA', 'B9' => 'SKOFJA LOKA', 'C1' => 'SKOFLJICA', 'C2' => 'SLOVENJ GRADEC', 'C4' => 'SLOVENSKE KONJICE', 'C7' => 'SOSTANJ', 'C9' => 'STORE', 'D2' => 'TOLMIN', 'D3' => 'TRBOVLJE', 'D4' => 'TREBNJE', 'D5' => 'TRZIC', 'D6' => 'TURNISCE', 'D7' => 'VELENJE', 'E1' => 'VIPAVA', 'E3' => 'VODICE', 'E5' => 'VRHNIKA', 'E6' => 'VUZENICA', 'E7' => 'ZAGORJE OB SAVI', 'F1' => 'ZELEZNIKI', 'F2' => 'ZIRI', 'F3' => 'ZRECE', 'G1' => 'DESTRNIK', 'G7' => 'DOMZALE', 'H1' => 'HOCE-SLIVNICA', 'H3' => 'HORJUL', 'H4' => 'JESENICE', 'H6' => 'KAMNIK', 'H7' => 'KOCEVJE', 'I3' => 'LENART', 'I4' => 'LENDAVA', 'I5' => 'LITIJA', 'I6' => 'LJUTOMER', 'I8' => 'LOVRENC NA POHORJU', 'J2' => 'MARIBOR', 'J4' => 'MIKLAVZ NA DRAVSKEM POLJU', 'J5' => 'MIREN-KOSTANJEVICA', 'J7' => 'NOVO MESTO', 'J8' => 'OPLOTNICA', 'J9' => 'PIRAN', 'K3' => 'POLZELA', 'K4' => 'PREBOLD', 'K6' => 'PREVALJE', 'K7' => 'PTUJ', 'K8' => 'RAVNE NA KOROSKEM', 'L1' => 'RIBNICA', 'L3' => 'RUSE', 'L6' => 'SEMPETER-VRTOJBA', 'L7' => 'SENTJUR PRI CELJU', 'L8' => 'SLOVENSKA BISTRICA', 'M8' => 'TRZIN', 'N3' => 'VOJNIK', 'N5' => 'ZALEC', 'N8' => 'ZUZEMBERK', 'O4' => 'LOG-DRAGOMER', 'O8' => 'POLJCANE', 'P5' => 'STRAZA',
			],
			'SJ' => [
				'21' => 'SVALBARD AND JAN MAYEN', '22' => 'SVALBARD AND JAN MAYEN',
			],
			'SK' => [
				'01' => 'BANSKA BYSTRICA', '02' => 'BRATISLAVA', '03' => 'KOSICE', '04' => 'NITRA', '05' => 'PRESOV', '06' => 'TRENCIN', '07' => 'TRNAVA', '08' => 'ZILINA',
			],
			'SL' => [
				'01' => 'EASTERN', '02' => 'NORTHERN', '03' => 'SOUTHERN', '04' => 'WESTERN AREA',
			],
			'SM' => [
				'01' => 'ACQUAVIVA', '02' => 'CHIESANUOVA', '07' => 'SAN MARINO', '09' => 'SERRAVALLE',
			],
			'SN' => [
				'01' => 'DAKAR', '03' => 'DIOURBEL', '05' => 'TAMBACOUNDA', '07' => 'THIES', '09' => 'FATICK', '10' => 'KAOLACK', '11' => 'KOLDA', '12' => 'ZIGUINCHOR', '13' => 'LOUGA', '14' => 'SAINT-LOUIS', '15' => 'MATAM', '16' => 'KAFFRINE', '17' => 'KEDOUGOU', '18' => 'SEDHIOU',
			],
			'SO' => [
				'01' => 'BAKOOL', '02' => 'BANAADIR', '03' => 'BARI', '04' => 'BAY', '05' => 'GALGUDUUD', '06' => 'GEDO', '07' => 'HIIRAAN', '08' => 'JUBBADA DHEXE', '09' => 'JUBBADA HOOSE', '10' => 'MUDUG', '12' => 'SANAAG', '13' => 'SHABEELLAHA DHEXE', '14' => 'SHABEELLAHA HOOSE', '18' => 'NUGAAL', '19' => 'TOGDHEER', '20' => 'WOQOOYI GALBEED', '21' => 'AWDAL', '22' => 'SOOL',
			],
			'SR' => [
				'10' => 'BROKOPONDO', '11' => 'COMMEWIJNE', '12' => 'CORONIE', '13' => 'MAROWIJNE', '14' => 'NICKERIE', '15' => 'PARA', '16' => 'PARAMARIBO', '17' => 'SARAMACCA', '19' => 'WANICA',
			],
			'SS' => [
				'01' => 'CENTRAL EQUATORIA', '02' => 'EASTERN EQUATORIA', '03' => 'JONGLEI', '04' => 'LAKES', '05' => 'NORTHERN BAHR EL GHAZAL', '06' => 'UNITY', '07' => 'UPPER NILE', '08' => 'WARRAP', '09' => 'WESTERN BAHR EL GHAZAL', '10' => 'WESTERN EQUATORIA',
			],
			'ST' => [
				'01' => 'PRINCIPE', '02' => 'SAO TOME',
			],
			'SV' => [
				'01' => 'AHUACHAPAN', '02' => 'CABANAS', '03' => 'CHALATENANGO', '04' => 'CUSCATLAN', '05' => 'LA LIBERTAD', '06' => 'LA PAZ', '07' => 'LA UNION', '08' => 'MORAZAN', '09' => 'SAN MIGUEL', '10' => 'SAN SALVADOR', '11' => 'SANTA ANA', '12' => 'SAN VICENTE', '13' => 'SONSONATE', '14' => 'USULUTAN',
			],
			'SX' => [
				'00' => 'SINT MAARTEN',
			],
			'SY' => [
				'01' => 'AL HASAKAH', '02' => 'AL LADHIQIYAH', '03' => 'AL QUNAYTIRAH', '04' => 'AR RAQQAH', '05' => 'AS SUWAYDA\'', '06' => 'DAR', '07' => 'DAYR AZ ZAWR', '08' => 'RIF DIMASHQ', '09' => 'HALAB', '10' => 'HAMAH', '11' => 'HIMS', '12' => 'IDLIB', '13' => 'DIMASHQ', '14' => 'TARTUS',
			],
			'SZ' => [
				'01' => 'HHOHHO', '02' => 'LUBOMBO', '03' => 'MANZINI', '04' => 'SHISELWENI',
			],
			'TC' => [
				'00' => 'TURKS AND CAICOS ISLANDS',
			],
			'TD' => [
				'01' => 'BATHA', '02' => 'WADI FIRA', '05' => 'GUERA', '06' => 'KANEM', '07' => 'LAC', '08' => 'LOGONE OCCIDENTAL', '09' => 'LOGONE ORIENTAL', '12' => 'OUADDAI', '13' => 'SALAMAT', '14' => 'TANDJILE', '04' => 'CHARI-BAGUIRMI', '16' => 'MAYO-KEBBI EST', '11' => 'MOYEN-CHARI', '18' => 'HADJER-LAMIS', '19' => 'MANDOUL', '20' => 'MAYO-KEBBI OUEST', '22' => 'BARH EL GHAZEL', '23' => 'BORKOU', '26' => 'TIBESTI',
			],
			'TF' => [
				'03' => 'FRENCH SOUTHERN AND ANTARCTIC LANDS',
			],
			'TG' => [
				'22' => 'CENTRALE', '23' => 'KARA', '24' => 'MARITIME', '25' => 'PLATEAUX', '26' => 'SAVANES',
			],
			'TH' => [
				'01' => 'MAE HONG SON', '02' => 'CHIANG MAI', '03' => 'CHIANG RAI', '04' => 'NAN', '05' => 'LAMPHUN', '06' => 'LAMPANG', '07' => 'PHRAE', '08' => 'TAK', '09' => 'SUKHOTHAI', '10' => 'UTTARADIT', '11' => 'KAMPHAENG PHET', '12' => 'PHITSANULOK', '13' => 'PHICHIT', '14' => 'PHETCHABUN', '15' => 'UTHAI THANI', '16' => 'NAKHON SAWAN', '17' => 'NONG KHAI', '18' => 'LOEI', '20' => 'SAKON NAKHON', '22' => 'KHON KAEN', '23' => 'KALASIN', '24' => 'MAHA SARAKHAM', '25' => 'ROI ET', '26' => 'CHAIYAPHUM', '27' => 'NAKHON RATCHASIMA', '28' => 'BURIRAM', '29' => 'SURIN', '30' => 'SISAKET', '31' => 'NARATHIWAT', '32' => 'CHAI NAT', '33' => 'SING BURI', '34' => 'LOP BURI', '35' => 'ANG THONG', '36' => 'PHRA NAKHON SI AYUTTHAYA', '37' => 'SARABURI', '38' => 'NONTHABURI', '39' => 'PATHUM THANI', '40' => 'KRUNG THEP', '41' => 'PHAYAO', '42' => 'SAMUT PRAKAN', '43' => 'NAKHON NAYOK', '44' => 'CHACHOENGSAO', '46' => 'CHON BURI', '47' => 'RAYONG', '48' => 'CHANTHABURI', '49' => 'TRAT', '50' => 'KANCHANABURI', '51' => 'SUPHAN BURI', '52' => 'RATCHABURI', '53' => 'NAKHON PATHOM', '54' => 'SAMUT SONGKHRAM', '55' => 'SAMUT SAKHON', '56' => 'PHETCHABURI', '57' => 'PRACHUAP KHIRI KHAN', '58' => 'CHUMPHON', '59' => 'RANONG', '60' => 'SURAT THANI', '61' => 'PHANGNGA', '62' => 'PHUKET', '63' => 'KRABI', '64' => 'NAKHON SI THAMMARAT', '65' => 'TRANG', '66' => 'PHATTHALUNG', '67' => 'SATUN', '68' => 'SONGKHLA', '69' => 'PATTANI', '70' => 'YALA', '72' => 'YASOTHON', '73' => 'NAKHON PHANOM', '45' => 'PRACHIN BURI', '75' => 'UBON RATCHATHANI', '76' => 'UDON THANI', '77' => 'AMNAT CHAROEN', '78' => 'MUKDAHAN', '79' => 'NONG BUA LAMPHU', '80' => 'SA KAEO',
			],
			'TJ' => [
				'00' => 'REGIONS OF REPUBLICAN SUBORDINATION', '01' => 'KUHISTONI BADAKHSHON', '02' => 'KHATLON', '03' => 'SUGHD', '04' => 'TAJIKISTAN',
			],
			'TK' => [
				'01' => 'TOKELAU', '02' => 'TOKELAU',
			],
			'TL' => [
				'DI' => 'TIMOR-LESTE',
			],
			'TM' => [
				'01' => 'AHAL', '02' => 'BALKAN', '03' => 'DASHOGUZ', '04' => 'LEBAP', '05' => 'MARY',
			],
			'TN' => [
				'02' => 'KASSERINE', '03' => 'KAIROUAN', '06' => 'JENDOUBA', '14' => 'EL KEF', '15' => 'AL MAHDIA', '16' => 'AL MUNASTIR', '17' => 'BAJAH', '18' => 'BIZERTE', '19' => 'NABEUL', '22' => 'SILIANA', '23' => 'SOUSSE', '27' => 'BEN AROUS', '28' => 'MADANIN', '29' => 'GABES', '30' => 'GAFSA', '31' => 'KEBILI', '32' => 'SFAX', '33' => 'SIDI BOU ZID', '34' => 'TATAOUINE', '35' => 'TOZEUR', '36' => 'TUNIS', '37' => 'ZAGHOUAN', '38' => 'AIANA', '39' => 'MANOUBA',
			],
			'TO' => [
				'01' => 'HA', '02' => 'TONGATAPU', '03' => 'VAVA',
			],
			'TR' => [
				'02' => 'ADIYAMAN', '03' => 'AFYONKARAHISAR', '04' => 'AGRI', '05' => 'AMASYA', '07' => 'ANTALYA', '08' => 'ARTVIN', '09' => 'AYDIN', '10' => 'BALIKESIR', '11' => 'BILECIK', '12' => 'BINGOL', '13' => 'BITLIS', '14' => 'BOLU', '15' => 'BURDUR', '16' => 'BURSA', '17' => 'CANAKKALE', '19' => 'CORUM', '20' => 'DENIZLI', '21' => 'DIYARBAKIR', '22' => 'EDIRNE', '23' => 'ELAZIG', '24' => 'ERZINCAN', '25' => 'ERZURUM', '26' => 'ESKISEHIR', '28' => 'GIRESUN', '31' => 'HATAY', '32' => 'MERSIN', '33' => 'ISPARTA', '34' => 'ISTANBUL', '35' => 'IZMIR', '37' => 'KASTAMONU', '38' => 'KAYSERI', '39' => 'KIRKLARELI', '40' => 'KIRSEHIR', '41' => 'KOCAELI', '43' => 'KUTAHYA', '44' => 'MALATYA', '45' => 'MANISA', '46' => 'KAHRAMANMARAS', '48' => 'MUGLA', '49' => 'MUS', '50' => 'NEVSEHIR', '52' => 'ORDU', '53' => 'RIZE', '54' => 'SAKARYA', '55' => 'SAMSUN', '57' => 'SINOP', '58' => 'SIVAS', '59' => 'TEKIRDAG', '60' => 'TOKAT', '61' => 'TRABZON', '62' => 'TUNCELI', '63' => 'SANLIURFA', '64' => 'USAK', '65' => 'VAN', '66' => 'YOZGAT', '68' => 'ANKARA', '69' => 'GUMUSHANE', '70' => 'HAKKARI', '71' => 'KONYA', '72' => 'MARDIN', '73' => 'NIGDE', '74' => 'SIIRT', '75' => 'AKSARAY', '76' => 'BATMAN', '77' => 'BAYBURT', '78' => 'KARAMAN', '79' => 'KIRIKKALE', '80' => 'SIRNAK', '81' => 'ADANA', '82' => 'CANKIRI', '83' => 'GAZIANTEP', '84' => 'KARS', '85' => 'ZONGULDAK', '86' => 'ARDAHAN', '87' => 'BARTIN', '88' => 'IGDIR', '89' => 'KARABUK', '90' => 'KILIS', '91' => 'OSMANIYE', '92' => 'YALOVA', '93' => 'DUZCE',
			],
			'TT' => [
				'01' => 'ARIMA', '02' => 'CARONI', '03' => 'MAYARO', '05' => 'PORT-OF-SPAIN', '06' => 'SAINT ANDREW', '08' => 'SAINT GEORGE', '10' => 'SAN FERNANDO', '11' => 'TOBAGO', '12' => 'VICTORIA', '13' => 'TRINIDAD AND TOBAGO', '14' => 'TRINIDAD AND TOBAGO', '15' => 'TRINIDAD AND TOBAGO', '16' => 'TRINIDAD AND TOBAGO', '17' => 'TRINIDAD AND TOBAGO', '18' => 'TRINIDAD AND TOBAGO', '19' => 'TRINIDAD AND TOBAGO', '20' => 'TRINIDAD AND TOBAGO', '21' => 'TRINIDAD AND TOBAGO', '22' => 'TRINIDAD AND TOBAGO', '23' => 'TRINIDAD AND TOBAGO',
			],
			'TV' => [
				'01' => 'TUVALU',
			],
			'TW' => [
				'01' => 'FU-CHIEN', '02' => 'KAO-HSIUNG', '03' => 'T\'AI-PEI', '04' => 'T\'AI-WAN',
			],
			'TZ' => [
				'02' => 'PWANI', '03' => 'DODOMA', '04' => 'IRINGA', '05' => 'KIGOMA', '06' => 'KILIMANJARO', '07' => 'LINDI', '08' => 'MARA', '09' => 'MBEYA', '10' => 'MOROGORO', '11' => 'MTWARA', '12' => 'MWANZA', '13' => 'PEMBA NORTH', '14' => 'RUVUMA', '15' => 'SHINYANGA', '16' => 'SINGIDA', '17' => 'TABORA', '18' => 'TANGA', '19' => 'KAGERA', '20' => 'PEMBA SOUTH', '21' => 'ZANZIBAR CENTRAL', '22' => 'ZANZIBAR NORTH', '23' => 'DAR ES SALAAM', '24' => 'RUKWA', '25' => 'ZANZIBAR URBAN', '26' => 'ARUSHA', '27' => 'MANYARA',
			],
			'UA' => [
				'01' => 'CHERKAS\'KA OBLAST\'', '02' => 'CHERNIHIVS\'KA OBLAST\'', '03' => 'CHERNIVETS\'KA OBLAST\'', '04' => 'DNIPROPETROVS\'KA OBLAST\'', '05' => 'DONETS\'KA OBLAST\'', '06' => 'IVANO-FRANKIVS\'KA OBLAST\'', '07' => 'KHARKIVS\'KA OBLAST\'', '08' => 'KHERSONS\'KA OBLAST\'', '09' => 'KHMEL\'NYTS\'KA OBLAST\'', '10' => 'KIROVOHRADS\'KA OBLAST\'', '11' => 'KRYM', '12' => 'KYYIV', '13' => 'KYYIVS\'KA OBLAST\'', '14' => 'LUHANS\'KA OBLAST\'', '15' => 'L\'VIVS\'KA OBLAST\'', '16' => 'MYKOLAYIVS\'KA OBLAST\'', '17' => 'ODES\'KA OBLAST\'', '18' => 'POLTAVS\'KA OBLAST\'', '19' => 'RIVNENS\'KA OBLAST\'', '20' => 'SEVASTOPOL\'', '21' => 'SUMS\'KA OBLAST\'', '22' => 'TERNOPIL\'S\'KA OBLAST\'', '23' => 'VINNYTS\'KA OBLAST\'', '24' => 'VOLYNS\'KA OBLAST\'', '25' => 'ZAKARPATS\'KA OBLAST\'', '26' => 'ZAPORIZ\'KA OBLAST\'', '27' => 'ZHYTOMYRS\'KA OBLAST\'',
			],
			'UG' => [
				'18' => 'KAMPALA DISTRICT', '26' => 'APAC', '28' => 'BUNDIBUGYO', '29' => 'BUSHENYI', '30' => 'GULU', '31' => 'HOIMA', '33' => 'JINJA', '34' => 'KABALE', '36' => 'KALANGALA', '37' => 'KAMPALA', '38' => 'KAMULI', '39' => 'KAPCHORWA', '40' => 'KASESE', '41' => 'KIBALE', '42' => 'KIBOGA', '43' => 'KISORO', '45' => 'KOTIDO', '46' => 'KUMI', '47' => 'LIRA', '50' => 'MASINDI', '52' => 'MBARARA', '56' => 'MUBENDE', '58' => 'NEBBI', '59' => 'NTUNGAMO', '60' => 'PALLISA', '61' => 'RAKAI', '65' => 'ADJUMANI', '66' => 'BUGIRI', '67' => 'BUSIA', '69' => 'KATAKWI', '70' => 'LUWERO', '71' => 'MASAKA', '72' => 'MOYO', '73' => 'NAKASONGOLA', '74' => 'SEMBABULE', '76' => 'TORORO', '77' => 'ARUA', '78' => 'IGANGA', '79' => 'KABAROLE', '80' => 'KABERAMAIDO', '81' => 'KAMWENGE', '82' => 'KANUNGU', '83' => 'KAYUNGA', '84' => 'KITGUM', '85' => 'KYENJOJO', '86' => 'MAYUGE', '87' => 'MBALE', '88' => 'MOROTO', '89' => 'MPIGI', '90' => 'MUKONO', '91' => 'NAKAPIRIPIRIT', '92' => 'PADER', '93' => 'RUKUNGIRI', '94' => 'SIRONKO', '95' => 'SOROTI', '96' => 'WAKISO', '97' => 'YUMBE', 'B6' => 'ABIM', 'B7' => 'AMOLATAR', 'B8' => 'AMURIA', 'B9' => 'AMURU', 'C1' => 'BUDAKA', 'C2' => 'BUDUDA', 'C3' => 'BUKEDEA', 'C4' => 'BUKWA', 'C5' => 'BULISA', 'C6' => 'BUTALEJA', 'C7' => 'DOKOLO', 'C9' => 'ISINGIRO', 'D1' => 'KAABONG', 'D2' => 'KALIRO', 'D4' => 'KOBOKO', 'D5' => 'LYANTONDE', 'D6' => 'MANAFWA', 'D7' => 'MARACHA', 'D8' => 'MITYANA', 'D9' => 'NAKASEKE', 'E1' => 'NAMUTUMBA', 'E2' => 'OYAM', 'E3' => 'AGAGO DISTRICT', 'E4' => 'ALEBTONG DISTRICT', 'E5' => 'AMUDAT DISTRICT', 'E7' => 'BUIKWE DISTRICT', 'E8' => 'BUKOMANSIMBI DISTRICT', 'E9' => 'BULAMBULI DISTRICT', 'F2' => 'BUVUMA DISTRICT', 'F3' => 'BUYENDE DISTRICT', 'F4' => 'GOMBA DISTRICT', 'F6' => 'KIBUKU DISTRICT', 'F7' => 'KIRYANDONGO DISTRICT', 'F8' => 'KOLE DISTRICT', 'F9' => 'KWEEN DISTRICT', 'G1' => 'KYANKWANZI DISTRICT', 'G2' => 'KYEGEGWA DISTRICT', 'G4' => 'LUUKA DISTRICT', 'G5' => 'LWENGO DISTRICT', 'G6' => 'MITOMA DISTRICT', 'G7' => 'NAMAYINGO DISTRICT', 'G8' => 'NAPAK DISTRICT', 'G9' => 'NGORA DISTRICT', 'H1' => 'NTOROKO DISTRICT', 'H3' => 'OTUKE DISTRICT', 'H4' => 'RUBIRIZI DISTRICT', 'H5' => 'SERERE DISTRICT', 'H6' => 'SHEEMA DISTRICT', 'H7' => 'ZOMBO DISTRICT',
			],
			'UM' => [
				'LQ' => 'PALMYRA ATOLL',
			],
			'US' => [
				'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS', 'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE', 'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO', 'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS', 'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND', 'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI', 'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA', 'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK', 'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA', 'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA', 'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH', 'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA', 'WI' => 'WISCONSIN', 'WY' => 'WYOMING', 'DC' => 'DISTRICT OF COLUMBIA',
			],
			'UY' => [
				'01' => 'ARTIGAS', '02' => 'CANELONES', '03' => 'CERRO LARGO', '04' => 'COLONIA', '05' => 'DURAZNO', '06' => 'FLORES', '07' => 'FLORIDA', '08' => 'LAVALLEJA', '09' => 'MALDONADO', '10' => 'MONTEVIDEO', '11' => 'PAYSANDU', '12' => 'RIO NEGRO', '13' => 'RIVERA', '14' => 'ROCHA', '15' => 'SALTO', '16' => 'SAN JOSE', '17' => 'SORIANO', '18' => 'TACUAREMBO', '19' => 'TREINTA Y TRES',
			],
			'UZ' => [
				'01' => 'ANDIJON', '02' => 'BUKHORO', '03' => 'FARGHONA', '05' => 'KHORAZM', '06' => 'NAMANGAN', '07' => 'NAWOIY', '08' => 'QASHQADARYO', '09' => 'QORAQALPOGHISTON', '10' => 'SAMARQAND', '12' => 'SURKHONDARYO', '13' => 'TOSHKENT SHAHRI', '14' => 'TOSHKENT', '15' => 'JIZZAX', '11' => 'SIRDARYO',
			],
			'VA' => [
				'00' => 'VATICAN CITY',
			],
			'VC' => [
				'01' => 'CHARLOTTE', '04' => 'SAINT GEORGE',
			],
			'VE' => [
				'01' => 'AMAZONAS', '02' => 'ANZOATEGUI', '03' => 'APURE', '04' => 'ARAGUA', '05' => 'BARINAS', '06' => 'BOLIVAR', '07' => 'CARABOBO', '08' => 'COJEDES', '09' => 'DELTA AMACURO', '11' => 'FALCON', '12' => 'GUARICO', '13' => 'LARA', '14' => 'MERIDA', '15' => 'MIRANDA', '16' => 'MONAGAS', '17' => 'NUEVA ESPARTA', '18' => 'PORTUGUESA', '19' => 'SUCRE', '20' => 'TACHIRA', '21' => 'TRUJILLO', '22' => 'YARACUY', '23' => 'ZULIA', '25' => 'DISTRITO FEDERAL', '26' => 'VARGAS',
			],
			'VG' => [
				'00' => 'BRITISH VIRGIN ISLANDS',
			],
			'VI' => [
				'01' => 'VIRGIN ISLANDS',
			],
			'VN' => [
				'01' => 'AN GIANG', '03' => 'BEN TRE', '05' => 'CAO BANG', '09' => 'DONG THAP', '13' => 'HAI PHONG', '20' => 'HO CHI MINH', '21' => 'KIEN GIANG', '23' => 'LAM DONG', '24' => 'LONG AN', '30' => 'QUANG NINH', '32' => 'SON LA', '33' => 'TAY NINH', '34' => 'THANH HOA', '35' => 'THAI BINH', '37' => 'TIEN GIANG', '39' => 'LANG SON', '43' => 'AN GIANG', '44' => 'DAK LAK', '45' => 'DONG NAI', '46' => 'DONG THAP', '47' => 'KIEN GIANG', '49' => 'SONG BE', '50' => 'VINH PHU', '51' => 'HA NOI', '52' => 'HO CHI MINH', '53' => 'BA RIA-VUNG TAU', '54' => 'BINH DINH', '55' => 'BINH THUAN', '58' => 'HA GIANG', '59' => 'HA TAY', '60' => 'HA TINH', '61' => 'HOA BINH', '62' => 'KHANH HOA', '63' => 'KON TUM', '64' => 'QUANG TRI', '65' => 'NAM HA', '66' => 'NGHE AN', '67' => 'NINH BINH', '68' => 'NINH THUAN', '69' => 'PHU YEN', '70' => 'QUANG BINH', '71' => 'QUANG NGAI', '72' => 'QUANG TRI', '73' => 'SOC TRANG', '74' => 'THUA THIEN', '75' => 'TRA VINH', '76' => 'TUYEN QUANG', '77' => 'VINH LONG', '78' => 'DA NANG', '79' => 'HAI DUONG', '80' => 'HA NAM', '81' => 'HUNG YEN', '82' => 'NAM DINH', '83' => 'PHU THO', '84' => 'QUANG NAM', '85' => 'THAI NGUYEN', '86' => 'VINH PUC PROVINCE', '87' => 'CAN THO', '88' => 'DAK LAK', '89' => 'LAI CHAU', '90' => 'LAO CAI', '92' => 'DIEN BIEN',
			],
			'VU' => [
				'07' => 'TORBA', '13' => 'SANMA', '15' => 'TAFEA', '16' => 'MALAMPA', '18' => 'SHEFA',
			],
			'WF' => [
				'01' => 'WALLIS AND FUTUNA ISLANDS',
			],
			'WS' => [
				'01' => 'A\'ANA', '03' => 'ATUA', '07' => 'GAGAIFOMAUGA', '08' => 'PALAULI', '10' => 'TUAMASAGA',
			],
			'YE' => [
				'01' => 'ABYAN', '02' => 'ADAN', '03' => 'AL MAHRAH', '04' => 'HADRAMAWT', '05' => 'SHABWAH', '08' => 'AL HUDAYDAH', '10' => 'AL MAHWIT', '11' => 'DHAMAR', '14' => 'MA\'RIB', '15' => 'SA\'DAH', '16' => 'SAN\'A\'', '18' => 'AD DALI', '19' => 'AMRAN', '20' => 'AL BAYDA\'', '21' => 'AL JAWF', '22' => 'HAJJAH', '23' => 'IBB', '24' => 'LAHIJ', '25' => 'TAIZZ', '26' => 'AMANAT AL ASIMAH', '27' => 'MUHAFAZAT RAYMAH',
			],
			'YT' => [
				'01' => 'ACOUA', '02' => 'BANDRABOUA', '03' => 'BANDRELE', '04' => 'BOUENI', '05' => 'CHICONI', '06' => 'CHIRONGUI', '07' => 'DZAOUDZI', '08' => 'KANI-KELI', '09' => 'KOUNGOU', '10' => 'MAMOUDZOU', '11' => 'MTSAMBORO', '12' => 'OUANGANI', '13' => 'PAMANDZI', '14' => 'SADA', '15' => 'TSINGONI',
			],
			'ZA' => [
				'02' => 'KWAZULU-NATAL', '03' => 'FREE STATE', '05' => 'EASTERN CAPE', '06' => 'GAUTENG', '07' => 'MPUMALANGA', '08' => 'NORTHERN CAPE', '09' => 'LIMPOPO', '10' => 'NORTH-WEST', '11' => 'WESTERN CAPE',
			],
			'ZM' => [
				'01' => 'WESTERN', '02' => 'CENTRAL', '03' => 'EASTERN', '04' => 'LUAPULA', '05' => 'NORTHERN', '06' => 'NORTH-WESTERN', '07' => 'SOUTHERN', '08' => 'COPPERBELT', '09' => 'LUSAKA',
			],
			'ZW' => [
				'01' => 'MANICALAND', '02' => 'MIDLANDS', '03' => 'MASHONALAND CENTRAL', '04' => 'MASHONALAND EAST', '05' => 'MASHONALAND WEST', '06' => 'MATABELELAND NORTH', '07' => 'MATABELELAND SOUTH', '08' => 'MASVINGO', '09' => 'BULAWAYO', '10' => 'HARARE',
			],
		];

		if (!isset($regions[$countryCode])) {
			return false;
		}

		foreach ($regions[$countryCode] as $regionCode => $region) {
			if (strtolower($region) == strtolower($regionName)) {
				return $regionCode;
			}
		}

		return false;
	}
}
