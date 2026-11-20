<?php

declare(strict_types=1);

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblemMapper;
use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Controller\ArticleController;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Controller\DocumentController;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;

final class DocumentControllerInputTest extends TestCase
{
    private const INVALID_ID = [null, true, 1.5, 0, -1, '-1', '1.5', '1e2', ' 1', '01', [], '999999999999999999999999999999'];

    private App $app;

    protected function setUp(): void
    {
        $this->app = new App(dirname(__DIR__, 4));
        $this->app->instance(CurrentExecutionContext::class, new CurrentExecutionContext(new ExecutionContextStore()));
    }

    public function testDocumentActionsValidateRequestIdsBeforeResolvingTheTenant(): void
    {
        $cases = [
            'detail' => [false, ['document_id' => '17', 'revision_id' => '2']],
            'capabilities' => [false, ['document_id' => '17', 'intent' => 'view']],
            'soloSave' => [true, ['document_id' => '17', 'expected_state_seq' => '0', 'structured_content' => [], 'request_id' => 'controller-input']],
            'bindResource' => [true, ['document_id' => '17', 'expected_state_seq' => '0', 'file_key' => 'file_' . str_repeat('1', 32), 'request_id' => 'controller-input']],
            'createVersion' => [true, ['document_id' => '17', 'expected_state_seq' => '0', 'reason' => 'Input boundary', 'idempotency_key' => 'controller-input']],
            'restoreVersion' => [true, ['document_id' => '17', 'revision_id' => '2', 'expected_state_seq' => '0', 'request_id' => 'controller-input']],
            'archive' => [true, ['document_id' => '17', 'expected_auth_revision' => '1', 'request_id' => 'controller-input']],
            'restore' => [true, ['document_id' => '17', 'expected_auth_revision' => '1', 'request_id' => 'controller-input']],
        ];
        $input = new ReflectionMethod(DocumentController::class, 'input');
        $id = new ReflectionMethod(DocumentController::class, 'id');
        $sequence = new ReflectionMethod(DocumentController::class, 'sequence');
        $mapper = new ApiProblemMapper();

        foreach ($cases as $method => [$post, $valid]) {
            $controller = $this->documentController($post, $valid);
            $parsed = $input->invoke($controller, $post, array_keys($valid));
            self::assertSame(17, $id->invoke($controller, $parsed, 'document_id'));
            if (isset($valid['expected_state_seq'])) {
                self::assertSame(0, $sequence->invoke($controller, $parsed, 'expected_state_seq'));
            }
            foreach (self::INVALID_ID as $value) {
                $bad = $valid;
                $bad['document_id'] = $value;
                try {
                    $this->documentController($post, $bad)->{$method}();
                    self::fail($method . ' accepted an invalid document ID');
                } catch (BusinessException $error) {
                    $problem = $mapper->map($error);
                    self::assertSame('DOCUMENT_INPUT_INVALID', $error->errorCode);
                    self::assertSame(400, $problem->httpStatus);
                    self::assertSame('DOCUMENT_INPUT_INVALID', $problem->data()['error_code']);
                }
            }
        }
    }

    public function testDocumentSaveRejectsInvalidSequences(): void
    {
        foreach ([false, 1.0, -1, '-1', '1e2', [], str_repeat('9', 30)] as $value) {
            $controller = $this->documentController(true, [
                'document_id' => '17',
                'expected_state_seq' => $value,
                'structured_content' => [],
                'request_id' => 'controller-input',
            ]);
            try {
                $controller->soloSave();
                self::fail('Document save accepted an invalid sequence');
            } catch (BusinessException $error) {
                self::assertSame('DOCUMENT_INPUT_INVALID', $error->errorCode);
            }
        }
    }

    public function testArticleBodyRejectsInvalidIds(): void
    {
        $mapper = new ApiProblemMapper();
        $administration = $this->createStub(ArticleAdministration::class);
        foreach (self::INVALID_ID as $value) {
            $this->app->instance(Request::class, (new Request())->withPost(['id' => $value, 'request_id' => 'controller-input']));
            try {
                (new ArticleController($this->app, $administration))->bodyDocument();
                self::fail('Article body accepted an invalid article ID');
            } catch (BusinessException $error) {
                $problem = $mapper->map($error);
                self::assertSame('ARTICLE_DOCUMENT_INPUT_INVALID', $error->errorCode);
                self::assertSame(400, $problem->httpStatus);
            }
        }
    }

    private function documentController(bool $post, array $input): DocumentController
    {
        $request = $post ? (new Request())->withPost($input) : (new Request())->withGet($input);
        $this->app->instance(Request::class, $request);
        return new DocumentController(
            $this->app,
            $this->createStub(DocumentCommands::class),
            $this->createStub(DocumentQueries::class),
        );
    }
}
