<?php

declare(strict_types=1);

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblemMapper;
use PeanutAdmin\Modules\Article\Controller\ArticleController;
use PeanutAdmin\Modules\Document\Controller\DocumentController;
use think\App;
use think\Request;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

// Compile/load the actual selected modules; no fabricated TenantContext, provider substitute or database.
$server = dirname(__DIR__, 4);
$app = new App($server);
$app->instance(App::class, $app);
$app->config->set(['mode' => 'multi-tenant'], 'deployment');
$registry = (new \app\platform\composition\module\ModuleDefinitionRegistryFactory($server))->fromModuleLock(
    new \app\platform\infrastructure\module\ModuleLockResolver($server, '../modules.lock'),
    ['roots' => [],'kernel_version' => '1.0.0','registered_client_keys' => ['admin-web','platform-web','pc-web','uniapp']],
);
$app->instance(CurrentExecutionContext::class, new CurrentExecutionContext(new ExecutionContextStore()));
$app->instance(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class, $registry);
(new \app\common\composition\ModuleComposition($app))->register($registry);
$mapper = new ApiProblemMapper();
$assertions = 0;
$expect = function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$cases = [
    'detail' => [false,['document_id' => '17','revision_id' => '2']],
    'capabilities' => [false,['document_id' => '17','intent' => 'view']],
    'soloSave' => [true,['document_id' => '17','expected_state_seq' => '0','structured_content' => [],'request_id' => 'controller-input']],
    'bindResource' => [true,['document_id' => '17','expected_state_seq' => '0','file_key' => 'file_' . str_repeat('1', 32),'request_id' => 'controller-input']],
    'createVersion' => [true,['document_id' => '17','expected_state_seq' => '0','reason' => 'Input boundary','idempotency_key' => 'controller-input']],
    'restoreVersion' => [true,['document_id' => '17','revision_id' => '2','expected_state_seq' => '0','request_id' => 'controller-input']],
    'archive' => [true,['document_id' => '17','expected_auth_revision' => '1','request_id' => 'controller-input']],
    'restore' => [true,['document_id' => '17','expected_auth_revision' => '1','request_id' => 'controller-input']],
];
$input = new ReflectionMethod(DocumentController::class, 'input');
$id = new ReflectionMethod(DocumentController::class, 'id');
$sequence = new ReflectionMethod(DocumentController::class, 'sequence');
$invalid = [null,true,1.5,0,-1,'-1','1.5','1e2',' 1','01',[],str_repeat('9', 30)];
foreach ($cases as $method => [$post,$valid]) {
    $request = $post ? (new Request())->withPost($valid) : (new Request())->withGet($valid);
    $app->instance(Request::class, $request);
    $controller = new DocumentController($app);
    $parsed = $input->invoke($controller, $post, array_keys($valid));
    $expect($id->invoke($controller, $parsed, 'document_id') === 17, 'actual Request IDs accept bounded decimal strings');
    if (isset($valid['expected_state_seq'])) {
        $expect($sequence->invoke($controller, $parsed, 'expected_state_seq') === 0, 'CAS zero remains valid');
    }
    foreach ($invalid as $value) {
        $bad = $valid;
        $bad['document_id'] = $value;
        $request = $post ? (new Request())->withPost($bad) : (new Request())->withGet($bad);
        $app->instance(Request::class, $request);
        try {
            (new DocumentController($app))->{$method}();
            throw new RuntimeException('invalid integer reached use case');
        } catch (BusinessException $error) {
            $problem = $mapper->map($error);
            $expect($error->errorCode === 'DOCUMENT_INPUT_INVALID' && $problem->httpStatus === 400 && $problem->data()['error_code'] === 'DOCUMENT_INPUT_INVALID', 'actual Controller input maps to stable public business error');
        }
    }
}
foreach ([false,1.0,-1,'-1','1e2',[],str_repeat('9', 30)] as $value) {
    $app->instance(Request::class, (new Request())->withPost(['document_id' => '17','expected_state_seq' => $value,'structured_content' => [],'request_id' => 'controller-input']));
    try {
        (new DocumentController($app))->soloSave();
        throw new RuntimeException('invalid sequence accepted');
    } catch (BusinessException $error) {
        $expect($error->errorCode === 'DOCUMENT_INPUT_INVALID', 'actual save Controller rejects invalid/overflow sequence');
    }
}
foreach ($invalid as $value) {
    $app->instance(Request::class, (new Request())->withPost(['id' => $value,'request_id' => 'controller-input']));
    try {
        (new ArticleController($app))->bodyDocument();
        throw new RuntimeException('invalid Article ID accepted');
    } catch (BusinessException $error) {
        $problem = $mapper->map($error);
        $expect($error->errorCode === 'ARTICLE_DOCUMENT_INPUT_INVALID' && $problem->httpStatus === 400, 'actual Article body Controller rejects invalid/overflow ID');
    }
}
echo 'DOCUMENT_CONTROLLER_INPUT_OK assertions=' . $assertions . ' real_request=true trusted_session=not_fabricated database=not_accessed success_use_cases=not_claimed' . PHP_EOL;
