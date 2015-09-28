<?php
/**
 * Created by PhpStorm.
 * User: zhangwenhao <zhangwenhao@ganji.com>
 * Date: 3/16/15
 * Time: 07:17
 */
require dirname ( __FILE__ ) . '/include/config.php';
class GetTransitInfo {

    protected $_handle = null;

    public function __construct() {
        $this->_handle = mysqli_connect(DbConfig::$HOST, DbConfig::$USERNAME, DbConfig::$PASSWORD, 'tool');
    }

    public function runHouseData() {
        $sql = "select * from find_house";
        $result = $this->_handle->query($sql);
        $totalNum = 0;
        while($row = mysqli_fetch_assoc($result)) {
        	if (!$row['address']) {
        		continue;
        	}
            $totalNum ++;
            $duration = $this->getDurationFromApi($row['address'], '华星时代广场-a座');
        }
        echo "total: {$totalNum}\n";
    }

    public function runLibData() {
        $sql = "select * from transit_info where duration between 3000 and 3600";
        $result = $this->_handle->query($sql);
        $totalNum = 0;
        while($row = mysqli_fetch_assoc($result)) {
            $sql = "select * from geo_data where id = {$row['ori_geo_id']}";
            $houseRes = $this->_handle->query($sql);
            $houseInfo = mysqli_fetch_assoc($houseRes);
            $totalNum ++;
            $this->getDurationFromApi($houseInfo['address'], '杭州图书馆');
        }
        echo "total: {$totalNum}\n";
    }

    public function getDurationFromApi($address, $desAddress) {
    	$ak = $this->getAk();
        $oriGeoInfo = $this->getGeoArr($address);
        if ($oriGeoInfo['lat'] == 0 || $oriGeoInfo['lng'] == 0) {
            return -1;
        }
        $desGeoInfo = $this->getGeoArr($desAddress);
        $sql = "select * from transit_info where ori_geo_id = {$oriGeoInfo['id']} and dest_geo_id = {$desGeoInfo['id']}";
        $transitRes = $this->_handle->query($sql);
        $row = mysqli_fetch_assoc($transitRes);
        if ($row) {
            return $row['duration'];
        }
        $oriGeo = "{$oriGeoInfo['lat']},{$oriGeoInfo['lng']}";
        $desGeo = "{$desGeoInfo['lat']},{$desGeoInfo['lng']}";
        $region = urlencode('杭州');
    	$url = "http://api.map.baidu.com/direction/v1?mode=transit&origin={$oriGeo}&destination={$desGeo}&origin_region={$region}&destination_region={$region}&output=json&ak={$ak}";
    	$json = file_get_contents($url);
		$obj = json_decode($json);
        if (!isset($obj->result->routes) ||count($obj->result->routes) <= 0) {
            echo "get routes fail, address:{$address}, origin: {$oriGeo}, url: {$url}\n";
            return -1;
        }
        $duration = $obj->result->routes[0]->scheme[0]->duration;
        if ($duration <= 0) {
            continue;
        }
        $time = time();
        $sql = "insert into transit_info (ori_geo_id, dest_geo_id, duration, create_time, update_time) values ({$oriGeoInfo['id']}, {$desGeoInfo['id']}, {$duration}, {$time}, {$time})";
        $this->_handle->query($sql);
        return $duration;
    }

    public function getGeoArr($address) {
        $address = str_replace('&nbsp', '', $address);
        $addresss[] = $address;
        $pattern = "/[\s,:(-]/u";
        if (preg_match($pattern, $address)) {
            $keywords = preg_split($pattern, $address);
            foreach($keywords as $keyword) {
                array_push($addresss, $keyword);
            }
        }
        do {
            $toGetAddress = array_shift($addresss);
            $ret = $this->_getGeoArr($toGetAddress);
        } while(($ret['lat'] == 0 || $ret['lng'] == 0) && $toGetAddress);
        return $ret;
    }

    public function _getGeoArr($address) {
        $sql = "select * from geo_data where address = '{$address}'";
        $result = $this->_handle->query($sql);
        $row = mysqli_fetch_assoc($result);
        if ($row) {
            return array('lat' => $row['lat'], 'lng' => $row['lng'], 'id' => $row['id']);
        }
        $encodeAddress = urlencode($address);
        $region = urlencode('杭州市');
        $ak = $this->getAk();
        $url = "http://api.map.baidu.com/place/v2/suggestion?query={$encodeAddress}&region={$region}&output=json&ak={$ak}";
        $json = file_get_contents($url);
        $obj = json_decode($json);
        $lat = 0;
        $lng = 0;
        foreach ($obj->result as $result) {
            if (isset($result->location)) {
                $lat = $result->location->lat;
                $lng = $result->location->lng;
                break;
            }
        }
        if ($lat == 0 || $lng == 0) {
            echo "get geo info fail, address: {$address}\n";
        }
        $time = time();
        $sql = "insert into geo_data (address, lat, lng, create_time, update_time) values ('{$address}', {$lat}, {$lng}, {$time}, {$time})";
        $this->_handle->query($sql);
        return array('lat' => $lat, 'lng' => $lng, 'id' => $this->_handle->insert_id);
    }

    public function getAk() {
        return Config::$BAIDUAK[rand(0, 1)];
    }
}

$ins = new GetTransitInfo();
$ins->runLibData();
echo "Finished!\n";





