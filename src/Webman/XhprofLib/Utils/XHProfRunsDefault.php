<?php

declare(strict_types=1);

namespace Yuanyuanli\Xhprof\Webman\XhprofLib\Utils;

use support\Db;
use support\Redis;
use Yuanyuanli\Xhprof\Webman\Xhprof;

class XHProfRunsDefault implements XHProfRuns
{
    public function __construct($dir = null)
    {
         
    }

    public static function get_run($run_id, $type, &$run_desc)
    {
        $run_desc = "XHProf Run (Namespace=$type)";
        $res = Redis::get(Xhprof::$key_prefix . ':xhprof_log:' . $run_id);
        return unserialize($res);
    }
    /**
     * 获取Webman顶层闭包的XHProf性能数据
     * @param array $xhprof_data XHProf/Tideways返回的性能数组
     * @return array 包含wt/cpu/mu/ct的性能数据，空数组表示未找到
     */
   public static function getWebmanAppClosureData(array $xhprof_data): array
    {
        // 优先匹配精准键名
        $target_keys = [
            'Webman\\App::Webman\\{closure}',
            'Webman\\App::Webman\\{closure}@1', // 兼容不同版本的闭包命名
            'Webman\\App::Webman\\{closure}@2'
        ];

        foreach ($target_keys as $key) {
            if (isset($xhprof_data[$key])) {
                return $xhprof_data[$key];
            }
        }

        // 兜底：模糊匹配
        foreach ($xhprof_data as $func_name => $data) {
            if (str_starts_with($func_name, 'Webman\\App::Webman\\{closure}')) {
                return $data;
            }
        }

        return [];
    }
    //实现接口方法
    public static function save_run($xhprof_data, $type, $run_id = null)
    {

        $webman_app_closure_data = self::getWebmanAppClosureData($xhprof_data);

        //根据响应时间判断是否需要记录
        if (Xhprof::$time_limit > 0 && $webman_app_closure_data['wt'] < (Xhprof::$time_limit * 1000 * 1000)) return false;
        //根据忽略配置判断是否忽略当前请求
        if (!XhprofLib::isIgnore()) return false;
        //控制日志长度
        // self::_checkLogNum();
        //数据存储至redis
        // $run_id = self::_saveToRedis($xhprof_data);
        // 保存至数据库

        self::saveToDb($xhprof_data,$webman_app_closure_data);
        return $run_id;
    }

    private static function saveToDb($xhprof_data, $webman_app_closure_data)
    {
        $request = Xhprof::getRequest();
        $method = $request->method();
       
        $requestTimeFloat = explode(' ', microtime());
        $requestTsMicro = array('sec' => $requestTimeFloat[1], 'usec' => $requestTimeFloat[0] * 1000000);

        $uri = $request->uri();
        // 获取 URI 中的 GET 参数
        $queryParams = [];
        if (strpos($uri, '?') !== false) {
            parse_str(parse_url($uri, PHP_URL_QUERY), $queryParams);
        }

        $saveData = [
            'url' =>  parse_url($uri, PHP_URL_PATH),
            'server_name' => $request->host() ?: (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : ''),
            'get' => json_encode($queryParams),
            'server' => json_encode([
                'host' => $request->host(),
                'method' => $request->method(),
                'uri' => $request->uri(),
                'path' => $request->path(),
                'query_string' => $request->queryString(),
            ]),
            'type' => $method,
            'ip' => XhprofLib::xhprof_get_ip(),
            'request_time' => $requestTsMicro['sec'],
            'request_time_micro' => $requestTsMicro['usec'],
            'profile' => json_encode(["profile" => $xhprof_data, "sql" => []]),
            'mu' => $webman_app_closure_data['mu'],
            'pmu' => $webman_app_closure_data['pmu'],
            'ct' => $webman_app_closure_data['ct'],
            'cpu' => $webman_app_closure_data['cpu'],
            'wt' => $webman_app_closure_data['wt'],
        ];

        return Db::table(Xhprof::$table_name)->insert($saveData);
    }

    /**
     * 控制日志长度
     * @return bool
     */
    protected static function _checkLogNum()
    {

        $num = Redis::incr(Xhprof::$key_prefix . ":run_id_num");
        if ($num > Xhprof::$log_num) {
            $old_run_id = Redis::rpop(Xhprof::$key_prefix . ':run_id');
            Redis::del(Xhprof::$key_prefix . ':request_log:' . $old_run_id);
            Redis::del(Xhprof::$key_prefix . ':xhprof_log:' . $old_run_id);
            Redis::decr(Xhprof::$key_prefix . ':run_id_num');  //计数-1
        }
        return true;
    }

    /**
     * 数据存储至redis
     * @return string
     */
    protected static function _saveToRedis($xhprof_data)
    {
        // print_r($xhprof_data);
        $run_id = uniqid();
        Redis::lPush(Xhprof::$key_prefix . ":run_id", $run_id);
        $wt = 0;   //请求总耗时
        $mu = 0;   //总消耗内存
        if (!empty($xhprof_data['main()']['wt']) && $xhprof_data['main()']['wt'] > 0) {
            $wt = round($xhprof_data['main()']['wt'] / 1000000, 4);        //1秒=1000毫秒=1000*1000微秒
            $mu = round($xhprof_data['main()']['mu'] / 1024 / 1024, 4);      //消耗内存 单位mb   1mb=1024kb=1024*1024b(字节)
        }

        $method = Xhprof::getRequest()->method();
        $http = Xhprof::getRequest()->header('x-forwarded-proto');
        $http = !empty($http) ? $http . "://" : "";
        $row = array(
            'request_uri' => $http . Xhprof::getRequest()->host() . Xhprof::getRequest()->uri(),
            'method' => $method,
            'wt' => $wt,
            'mu' => $mu,
            'ip' => XhprofLib::xhprof_get_ip(),
            'create_time' => time(),  //请求时间
        );
        $key = Xhprof::$key_prefix . ':request_log:' . $run_id;  //请求列表log
        Redis::set($key, json_encode($row));
        $key = Xhprof::$key_prefix . ':xhprof_log:' . $run_id;   //列表存储log
        $xhprof_data_str = serialize($xhprof_data);
        if (!empty($xhprof_data_str)) Redis::set($key, $xhprof_data_str);
        return $run_id;
    }



}
