<?php

declare(strict_types=1);

namespace app\adminapi\controller\generator;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\generator\GeneratorService;
use app\adminapi\services\generator\GeneratorArchiveService;
use app\adminapi\validate\generator\GeneratorValidate;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\response\Json;

class GeneratorController extends BaseAdminController
{
    public function __construct(App $app, protected readonly GeneratorService $generator)
    {
        parent::__construct($app);
    }
    public function sourceTables()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.source');
        $result = $this->generator->sourceTables($params);
        return $this->data($result);
    }

    public function lists()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.lists');
        $result = $this->generator->lists($this->adminId, $params);
        return $this->data($result);
    }

    public function detail()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.id');
        $result = $this->generator->detail($this->adminId, (int) $params['id']);
        return $this->data($result);
    }

    public function import()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.import');
        $this->generator->importTables($this->adminId, $params['table_names']);
        return $this->success('导入成功');
    }

    public function sync()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.id');
        $this->generator->sync($this->adminId, (int) $params['id']);
        return $this->success('同步成功');
    }

    public function update()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.update');
        $this->generator->update($this->adminId, $params);
        return $this->success('保存成功');
    }

    public function delete()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.ids');
        $this->generator->delete($this->adminId, $params['ids']);
        return $this->success('删除成功');
    }

    public function preview()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.id');
        $result = $this->generator->preview($this->adminId, (int) $params['id']);
        return $this->data($result);
    }

    public function generate()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.ids');
        $result = $this->generator->generate($this->adminId, $params['ids']);
        return $this->data($result);
    }

    public function download()
    {
        $this->assertInstanceToolAccess();

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.download');
        $file = $this->generator->consumeDownload($this->adminId, (string) $params['token']);
        $adminId = $this->adminId;
        register_shutdown_function(static function () use ($file, $adminId): void {
            GeneratorArchiveService::cleanupAfterResponse($file['archive_path'], $adminId);
        });
        return download($file['path'], $file['file_name']);
    }

    public function models()
    {
        $this->assertInstanceToolAccess();

        return $this->data($this->generator->models($this->adminId));
    }

    private function assertInstanceToolAccess(): void
    {
        $guard = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'));
        if (!$guard->allows()) {
            throw \app\common\http\ApiProblem::fromEnvelope('实例级开发工具仅在 standalone 部署中可用', null, 40300);
        }
    }
}
