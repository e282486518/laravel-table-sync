<?php

namespace e282486518\LaravelTableSync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 增量同步数据到数据表
 */
abstract class SyncService
{
    // A服务器API地址
    protected string $apiUrl = '';

    // API令牌
    protected string $apiToken = '';

    /**
     * @var int 会议系统年份分类
     */
    protected int $year = 2025;

    /**
     * @var int 会议系统栏目分类
     */
    protected int $catid = 3;

    /**
     * @var string 主键名
     */
    protected string $key = 'id';

    /**
     * @var array|int[] 添加数据时, 本服务器额外增加的字段
     */
    protected array $mapping_add = [
        'is_pay'   => 0,   // 是否付款
        'is_check' => 0,   // 是否报名审核
        'is_sign'  => 1,   // 签到时报名
        'expoid'   => 1,   // 展会ID
    ];

    /**
     * @var array|string[] 远程服务器多余的字段, 写入/更新时需过滤掉
     */
    protected array $filter = ['agenda', 'guests', 'partner', 'creator'];

    /**
     * @var array 字段名称替换, 格式: [服务器字段名1 => 本地字段名1, 服务器字段名2 => 本地字段名2, ...]
     */
    protected array $mapping_replace = [];

    /**
     * @var array 有哪些字段需要特殊处理的, 将json字符串转化成json
     */
    protected array $json_fields = ['title', 'content', 'addrress'];

    /**
     * Redis key, 格式: key + 表名
     */
    const REDISKEY = 'sync_last_time_';

    protected Model $_model;

    public function __construct($model, $year = 2025, $catid = 1)
    {
        $this->_model = $model;
        $this->year = $year;
        $this->catid = $catid;
    }

    /**
     * 执行数据同步
     */
    public function sync(): bool
    {
        try {
            // 获取上次同步时间
            $lastSyncTime = $this->getLastSyncTime();
            Log::info("更新 {$this->getTable()} 表开始, 最后更新时间: " . $lastSyncTime->format('Y-m-d H:i:s'));

            // 从A服务器获取更新数据
            $response = $this->fetchUpdatesFromServer($lastSyncTime);

            if ($response['err'] > 0 || empty($response['data'])) {
                Log::info("远程 {$this->getTable()} 表无更新数据");
                $this->updateLastSyncTime(Carbon::now());
                return true;
            }

            // 处理获取到的数据
            $this->processMeetingData($response['data']);

            // 更新最后同步时间
            $latestUpdate = $response['latest_update']
                ? Carbon::createFromFormat('Y-m-d H:i:s', $response['latest_update'])
                : Carbon::now();

            $this->updateLastSyncTime($latestUpdate);

            Log::info("更新 {$this->getTable()} 表成功. 最后更新时间: " . $latestUpdate->format('Y-m-d H:i:s'));
            return true;
        } catch (\Exception $e) {
            Log::error("数据表 {$this->getTable()} 更新失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 从A服务器获取更新数据
     * @throws \Exception
     */
    protected function fetchUpdatesFromServer(Carbon $since)
    {
        $response = Http::withOptions([
            'verify' => false, // 禁用 SSL 验证
        ])->withHeaders([
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Accept' => 'application/json'
        ])->get($this->apiUrl, [
            'time' => $since->format('Y-m-d H:i:s'), // 最后更新时间
            'catid' => $this->catid, // 栏目
            'year' => $this->year, // 年份
        ]);

        if (!$response->successful()) {
            throw new \Exception("远程获取更新数据失败: " . $response->body());
        }

        return $response->json();
    }

    /**
     * 处理会议数据
     */
    protected function processMeetingData(array $meetings): void
    {
        foreach ($meetings as $meetingData) {
            // 处理字段
            $processedData = $this->processJsonFields($meetingData);

            $id = $processedData[$this->key]; // 主键

            // 状态为0表示需要删除
            if ($this->is_delete($processedData)) {
                $this->handleDeletion($id);
                Log::info("删除数据, id=" . $id);
                continue;
            }

            // 检查记录是否存在
            $meeting = $this->_model->find($id);

            if ($meeting) {
                // 更新现有记录，保留B服务器特有字段
                $this->updateMeeting($meeting, $processedData);
                Log::info("更新数据, id=" . $id);
            } else {
                // 创建新记录，设置B服务器特有字段默认值
                $this->createMeeting($processedData);
                Log::info("创建数据, id=" . $id);
            }
        }
    }

    /**
     * 判断是否被删除
     * @param $data
     * @return bool
     */
    protected function is_delete($data): bool
    {
        return $data['status'] == 0;
    }

    /**
     * 处理JSON字段
     */
    protected function processJsonFields(array $data): array
    {
        // 先删除字段
        $data = Arr::except($data, array_keys($this->mapping_add));
        $data = Arr::except($data, $this->filter);
        // 再替换字段名
        foreach ($this->mapping_replace as $key_serv => $key_local) {
            if (isset($data[$key_serv])) {
                $data[$key_local] = $data[$key_serv]; // 赋值给本地字段
                unset($data[$key_serv]); // 删除原服务器字段，避免冲突
            }
        }
        // json字符串 => json
        foreach ($this->json_fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = json_decode($data[$field], true);
                // 容错：若JSON解码失败，保留原字符串（避免数据丢失）
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning("JSON解码失败，字段: {$field}，值: {$data[$field]}");
                    $data[$field] = $data[$field]; // 保留原字符串
                }
            }
        }
        return $data;
    }

    /**
     * 创建新数据
     */
    protected function createMeeting(array $data): void
    {
        // 添加本服务特有的字段, 默认值
        $data = array_merge($data, $this->mapping_add);
        $model = $this->_model->create($data);
        $this->afterCreate($model->{$this->key});
    }

    /**
     * 更新现有数据
     */
    protected function updateMeeting(Model $meeting, array $data): void
    {
        // 保留本地特有字段，只更新从远程获取的字段
        $meeting->update($data);
        $this->afterUpdate($meeting->{$this->key});
    }

    /**
     * 更新后处理数据
     * @param $id
     * @return void
     */
    protected function afterUpdate($id): void {
        //$this->_model->getOne($id, true);
    }
    
    /**
     * 创建后处理数据
     * @param $id
     * @return void
     */
    protected function afterCreate($id): void {
        //$this->_model->getOne($id, true);
    }

    /**
     * 删除数据, 物理删除
     */
    protected function handleDeletion($id): void
    {
        $meeting = $this->_model->find($id);
        if ($meeting) {
            $meeting->delete();
        }
    }

    /**
     * 获取上次同步时间
     */
    protected function getLastSyncTime(): bool|Carbon
    {
        $lastSync = Redis::get(self::REDISKEY . $this->getTable());

        if (!$lastSync) {
            // 如果没有同步记录，默认使用系统最早时间
            return Carbon::createFromFormat('Y-m-d H:i:s', '2000-01-01 00:00:00');
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $lastSync);
    }

    /**
     * 更新最后同步时间
     */
    protected function updateLastSyncTime(Carbon $time): void
    {
        Redis::set(self::REDISKEY . $this->getTable(), $time->format('Y-m-d H:i:s'));
    }

    protected function getTable(): string {
        return $this->_model->getTable();
    }
}
