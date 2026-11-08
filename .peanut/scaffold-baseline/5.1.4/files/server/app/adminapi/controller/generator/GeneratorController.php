<?php

declare(strict_types=1);

namespace app\adminapi\controller\generator;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\generator\GeneratorService;
use app\adminapi\services\generator\GeneratorArchiveService;
use app\adminapi\validate\generator\GeneratorValidate;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\response\Json;

/** @property-read GeneratorService $generator 当前 App 中声明式解析的控制器依赖。 */
class GeneratorController extends BaseAdminController
{
    protected string $generatorClass = GeneratorService::class;

    public function sourceTables()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.source');
        $result = $this->generator->sourceTables($params);
        return $this->data($result);
    }

    public function lists()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.lists');
        $result = $this->generator->lists($this->adminId, $params);
        return $this->data($result);
    }

    public function detail()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->get();
        $this->validate($params, GeneratorValidate::class . '.id');
        $result = $this->generator->detail($this->adminId, (int) $params['id']);
        return $this->data($result);
    }

    public function import()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.import');
        $this->generator->importTables($this->adminId, $params['table_names']);
        return $this->success('导入成功');
    }

    public function sync()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.id');
        $this->generator->sync($this->adminId, (int) $params['id']);
        return $this->success('同步成功');
    }

    public function update()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.update');
        $this->generator->update($this->adminId, $params);
        return $this->success('保存成功');
    }

    public function delete()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.ids');
        $this->generator->delete($this->adminId, $params['ids']);
        return $this->success('删除成功');
    }

    public function preview()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.id');
        $result = $this->generator->preview($this->adminId, (int) $params['id']);
        return $this->data($result);
    }

    public function generate()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $params = $this->request->post();
        $this->validate($params, GeneratorValidate::class . '.ids');
        $result = $this->generator->generate($this->adminId, $params['ids']);
        return $this->data($result);
    }

    public function download()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

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
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        return $this->data($this->generator->models($this->adminId));
    }

    private function instanceToolAccessDenial(): ?Json
    {
        $guard = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'));
        return $guard->allows()
            ? null
            : throw \app\common\http\ApiProblem::fromEnvelope('实例级开发工具仅在 standalone 部署中可用', null, 40300);
    }
}
