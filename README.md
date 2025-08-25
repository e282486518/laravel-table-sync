### 使用方式

步骤一: 
```
composer require e282486518/laravel-table-sync
```

步骤二: 继承服务

```php
<?php

namespace App\Services;

use App\Models\Meeting;
use e282486518\LaravelTableSync\SyncService;

/**
 * 增量同步数据到c_meeting表
 */
class MeetingSyncService extends SyncService
{
    // A服务器API地址
    protected string $apiUrl = 'https://api.xxx.com/api/meeting/sync';

    // API令牌
    protected string $apiToken = 'xxxxxx';

    /**
     * 更新数据
     * @param $id
     * @return void
     */
    protected function afterUpdate($id): void {
        Meeting::getOne($id, true);
    }

}

```

步骤三: 在命令行中调用

```php
    public function handle() {
        $serv = new MeetingSyncService(new Meeting());
        if ($serv->sync()) {
            echo "更新成功...\n";
        } else {
            echo "更新出错...\n";
        }
        return Command::SUCCESS;
    }
```

步骤四: 执行命令

可以使用 `php artisan  xxxxx` 执行第三步定义的命令

但是, 这里推荐使用 Schedule 执行命令, 参考laravel文档, 用一个cron命令管理项目中所有自动执行的命令
`php artisan schedule:run`


### 服务端代码

```php
<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MeetingController extends Controller
{

    /**
     * 增量更新数据
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync(Request $request)
    {
        // 最后更新时间
        try {
            $request->validate([
                'time' => 'required|date_format:Y-m-d H:i:s',
                'year' => 'date_format:Y',
                'catid' => 'integer',
            ]);
        } catch (ValidationException $e) {
            // 自定义返回内容
            return response()->json([
                'err' => 1,
                'msg' => $e->errors(),
                'data' => []
            ], 200);
        }
        $time = $request->input('time');
        $year = $request->input('year', 0);
        $catid = $request->input('catid', 0);
        // 获取更新的数据
        $model = Meeting::query()->where('updated_at', '>', $time);
        if (!empty($year)) {
            $model->where('year', $year);
        }
        if (!empty($catid)) {
            $model->where('cat_id', $catid);
        }
        $meetings = $model->orderBy('updated_at', 'desc')->get();
        // 最后更新时间
        if (!$meetings->isEmpty()) {
            $latestUpdate = $meetings->first()->updated_at->format('Y-m-d H:i:s');
        } else {
            $latestUpdate = $time;
        }
        // 返回
        return response()->json([
            'err' => 0,
            'msg' => 'success',
            'data' => $meetings,
            'latest_update' => $latestUpdate
        ])->setEncodingOptions(320);
    }
}


```



