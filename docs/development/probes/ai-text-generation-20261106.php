<?php

declare(strict_types=1);

/*
 * php probe.php APP_ROOT
 * php probe.php APP_ROOT --live https://provider.example /v1 model /private/tmp/result.json < one-use-key
 * The default transport never connects. Live mode sends only the fixture topic, once, using the registered route.
 */
use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\BusinessException;
use app\common\execution\{AdminExecutionContext, CurrentExecutionContext, ExecutionContextStore};
use app\common\infrastructure\http\GuzzleOutboundHttpTransport;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\DataScopePolicy;
use app\common\value\http\{OutboundHttpRequest, OutboundHttpResponse};
use Custom\Modules\AiAssistant\Contract\AiTextGeneration;
use Custom\Modules\AiAssistant\Service\{AiAccess, AiConfiguration, AiProtocol, AiQuota, AiTextGenerator, AiWorkspace};
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use think\db\BaseQuery;
use think\facade\Db;
use think\Model;

if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '1');
$root = $argv[1] ?? dirname(__DIR__, 3);
$live = ($argv[2] ?? '') === '--live';
if ($argc > 2 && (!$live || $argc !== 7)) throw new RuntimeException('Expected APP_ROOT [--live HTTPS_ORIGIN API_PREFIX MODEL NEW_RESULT_FILE]');
require $root . '/server/vendor/autoload.php';
spl_autoload_register(static function ($class) use ($root) {
    $prefix = 'Custom\\Modules\\AiAssistant\\';
    if (str_starts_with($class, $prefix)) {
        require $root . '/server/app/modules/custom/ai_assistant/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
}, true, true);

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function denied(string $code, callable $work): void {
    try { $work(); } catch (BusinessException $e) {
        check($e->errorCode === $code, 'Expected ' . $code . ', got ' . $e->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection ' . $code);
}

// Supply only host identity/permissions, module installation, tenant scope and audit/log sinks in this disposable process.
final class ProbeAuthorization {
    public bool $allowed = true;
    public function principal(TenantContext $c): int { return $c->memberId; }
    public function decide(TenantContext $c, int $id, string $permission): object {
        return (object)['allowed' => $this->allowed && $c->memberId === $id && in_array($permission, ['custom.ai-assistant.use','custom.ai-assistant.configure'], true)];
    }
}
final class ProbeScope {
    public function queryConstraint(TenantContext $c, string $resource, string $op): int { return $c->tenantId; }
    public function decideCreate(mixed ...$args): object { return (object)['allowed' => true]; }
    public function apply(BaseQuery $q, int $tenant): void { $q->where('tenant_id', $tenant); }
}
final class ProbeHost {
    public function assertHttp(string $key, string $op): void { check($key === 'custom.ai-assistant' && $op === 'http.admin', 'module boundary'); }
    public function appendTenantMember(mixed ...$args): void {}
    public static function warning(mixed ...$args): void {}
}
foreach ([
    'app\\common\\contract\\authorization\\AdminAuthorizationQuery' => ProbeAuthorization::class,
    'PeanutAdmin\\DataPermission\\Engine\\DataPermissionEngine' => ProbeScope::class,
    'PeanutAdmin\\DataPermission\\Constraint\\ThinkPhpQueryConstraintApplier' => ProbeScope::class,
    'app\\common\\infrastructure\\module\\ModuleExecutionBoundary' => ProbeHost::class,
    'app\\common\\services\\audit\\AuditContractHost' => ProbeHost::class,
    'app\\common\\infrastructure\\runtime\\OperationalLog' => ProbeHost::class,
] as $host => $fixture) class_alias($fixture, $host);

Db::setConfig(['default' => 'probe', 'connections' => ['probe' => ['type' => 'sqlite', 'database' => ':memory:']]]);
$policy = new class implements DataScopePolicy {
    public function applyTo(BaseQuery $q): void { $q->where('tenant_id', 1); }
    public function prepareWrite(Model $row): void { $row->tenant_id = 1; }
};
Model::maker(static function (Model $m) use ($policy) {
    if ($m instanceof TenantOwnedModel) $m->setDataScopePolicy($policy);
});
$common = 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, created_by INTEGER, title TEXT, version INTEGER, status TEXT, details TEXT, request_id TEXT, created_at INTEGER, updated_at INTEGER';
foreach ([
    'config' => 'kind TEXT, secret_payload TEXT, deleted_at INTEGER, UNIQUE(tenant_id,request_id)',
    'event' => 'parent_id INTEGER, actor_type TEXT, UNIQUE(tenant_id,request_id)',
    'quota' => 'connection_id INTEGER, period TEXT, reserved_requests INTEGER, confirmed_requests INTEGER, unknown_requests INTEGER, UNIQUE(tenant_id,connection_id,period)',
    'text_generation' => 'request_hash TEXT, candidate TEXT, UNIQUE(tenant_id,created_by,request_id)',
] as $table => $specific) Db::execute("CREATE TABLE ai_assistant_$table ($common,$specific)");

final class ProbeTransport implements OutboundHttpTransport {
    public int $calls = 0;
    public ?int $status = null;
    public ?string $trace = null;
    public string $mode = 'candidate';
    public ?Closure $inFlight = null;
    public function __construct(private ?OutboundHttpTransport $native) {}
    public function send(OutboundHttpRequest $request): OutboundHttpResponse {
        ++$this->calls;
        check(!Db::connect()->getPdo()->inTransaction() && !$request->retrySafe && !$request->allowRedirects, 'one POST outside transaction');
        if ($this->inFlight !== null) { ($this->inFlight)(); }
        if ($this->native !== null) {
            $r = $this->native->send($request);
        } else {
            if ($this->mode === 'unknown') throw new RuntimeException('fixture interrupted');
            $body = json_decode($request->body, true, 64, JSON_THROW_ON_ERROR);
            $messages = $body['messages'];
            check(str_contains(end($messages)['content'], '公共图书馆') && !str_contains(end($messages)['content'], '来源固定版本'), 'explicit member input');
            $anthropic = str_ends_with($request->url, '/messages');
            check($body['model'] === ($anthropic ? 'dev-messages' : 'dev-chat'), 'route-selected provider model');
            $payload = $anthropic ? ['id'=>'msg_fixture','type'=>'message','role'=>'assistant','stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>'候选乙']]]
                : ['choices'=>[['finish_reason'=>'stop','message'=>['role'=>'assistant','content'=>'候选甲']]],'usage'=>['prompt_tokens'=>5,'completion_tokens'=>4]];
            $sink = fopen($request->sink, 'w+b');
            fwrite($sink, $this->mode === 'bad_json' ? '<not-json>' : json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sink);
            $r = new OutboundHttpResponse($this->mode === 'rejected' ? 401 : 200, '', ['content-type'=>'application/json','x-request-id'=>'dev-trace']);
        }
        $this->status = $r->status;
        $trace = $r->headers['x-request-id'] ?? $r->headers['request-id'] ?? null;
        $this->trace = is_string($trace) && preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$trace) ? $trace : null;
        return $r;
    }
}

foreach (['Configuration','GenerationTask','Event','Quota','TextGeneration'] as $name) {
    $type = 'Custom\\Modules\\AiAssistant\\Model\\' . $name;
    check((new $type())->getPk() === 'id', $name . ' must update by its declared primary key');
}

$actorRef = new ReflectionClass(TenantContext::class);
$actor = $actorRef->newInstanceWithoutConstructor();
foreach (['tenantId'=>1,'memberId'=>7,'accountId'=>9,'requestId'=>'dev-admin-text'] as $field=>$value) $actorRef->getProperty($field)->setValue($actor,$value);
$store = new ExecutionContextStore();
$current = new CurrentExecutionContext($store);
$auth = new ProbeAuthorization();
$access = new AiAccess($auth, new ProbeScope(), new ProbeScope(), $current, new ProbeHost());
$secrets = new SodiumSecretProtector(['dev' => random_bytes(32)], 'dev');
$config = new AiConfiguration($access, $secrets);
$transport = new ProbeTransport($live ? new GuzzleOutboundHttpTransport($current) : null);
$public = new AiTextGeneration(new AiTextGenerator($current, new ProbeHost(), $access, $config, new AiQuota($access), new AiProtocol(), $transport));
$store->run(new AdminExecutionContext($actor,'http.admin',['id'=>7]), function () use ($actor,$config,$transport,$public,$auth,$access,$live,$argv) {
    $key = $live ? trim(stream_get_contents(STDIN,8193)) : 'dev-probe-only-key';
    check(strlen($key) >= 8 && strlen($key) <= 4096 && !preg_match('/[\r\n\x00]/',$key), 'expected one private credential on stdin');
    $resultFile = null;
    if ($live) {
        umask(0077);
        $resultFile = fopen($argv[6], 'x');
        check($resultFile !== false, 'result file must be new');
    }
    $make = function (string $kind, array $data, string $tag) use ($config,$actor,$key,$access) {
        $r = $config->save($actor,$kind,null,null,'DEV '.$tag,$data,'cfg-'.$tag);
        if ($kind === 'connection') {
            $version = $r['version'];
            $request = 'secret-'.$tag;
            $r = $config->secret($actor,$r['id'],$version,$key,$request);
            $read = fn() => Db::table('ai_assistant_config')->where('id',$r['id'])->find();
            $stored = $read();
            check((int)$stored['version'] === $version+1 && $stored['secret_payload'] && json_decode($stored['details'],true)['credential_configured'], 'credential persisted');
            check(AiAccess::hash($config->secret($actor,$r['id'],$version,$key,$request)) === AiAccess::hash($r) && $read()['secret_payload'] === $stored['secret_payload'], 'replay does not rotate ciphertext');
            if ($tag === 'one-connection') {
                $event = Db::table('ai_assistant_event')->where('request_id',$request)->find();
                $old = json_decode($event['details'],true,64,JSON_THROW_ON_ERROR);
                unset($old['evidence'],$event['id']);
                $event['details'] = json_encode($old,JSON_THROW_ON_ERROR);
                $event['request_id'] = 'DEV-legacy-receipt';
                Db::table('ai_assistant_event')->insert($event);
                denied('AI_CREDENTIAL_RECEIPT_UNVERIFIED', fn()=> $config->secret($actor,$r['id'],$version,$key,'DEV-legacy-receipt'));
                $sources = (new ReflectionClass(\Custom\Modules\AiAssistant\Service\AiSources::class))->newInstanceWithoutConstructor();
                $status = new \Custom\Modules\AiAssistant\Service\AiRequestStatus($access,$sources);
                $descriptor = ['endpoint'=>'saveCredential','kind'=>'connection','id'=>$r['id']];
                $historic = $status->status($actor,$descriptor + ['request_id'=>'DEV-legacy-receipt']);
                $verified = $status->status($actor,$descriptor + ['request_id'=>$request]);
                check($historic['state']==='unconfirmed' && $historic['anchor_present'] && $historic['result']['version']===$version+1
                    && $verified['state']==='applied', 'historic event is visible, only verified event confirms storage');
                check($read()['secret_payload'] === $stored['secret_payload'], 'historical event cannot rotate credential');
            }
        }
        return $config->state($actor,$kind,$r['id'],$r['version'],'enable','enable-'.$tag,'DEV probe')['id'];
    };
    $configure = function (string $protocol,string $origin,string $prefix,string $model,string $tag) use ($make) {
        $connection = $make('connection',['protocol'=>$protocol,'origin'=>$origin,'api_prefix'=>$prefix,'responsibility'=>'institution_provider','responsibility_note'=>'DEV','daily_request_limit'=>12],$tag.'-connection');
        $m = $make('model',['connection_id'=>$connection,'provider_model_id'=>$model,'capability'=>'text','input_limit_chars'=>4000,'max_output_tokens'=>1600,'supports_background'=>false],$tag.'-model');
        $t = $make('template',['capability'=>'text','instructions'=>'根据主题和提纲撰写两段中文正文，不虚构具体统计数字。','scope_note'=>'DEV'],$tag.'-template');
        return $make('route',['capability'=>'text','model_id'=>$m,'template_id'=>$t,'consumer_module'=>'peanut.article','scope_note'=>'DEV'],$tag.'-route');
    };
    $route = $configure('openai_text',$live?$argv[3]:'https://dev.example.invalid',$live?$argv[4]:'/v1',$live?$argv[5]:'dev-chat','one');
    $request = ['route_id'=>$route,'consumer_module'=>'peanut.article','topic'=>'介绍社区公共图书馆的阅读活动','outline'=>'先写活动意义，再写读者如何参与。','request_id'=>'dev-text-'.bin2hex(random_bytes(8)),'confirm_send'=>true];
    // SQLite JSON_EXTRACT returns a scalar. MySQL exposes that scalar via JSON_UNQUOTE.
    Db::connect()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value, 1);
    $workspace = new AiWorkspace($access);
    $routes = fn(string $consumer = 'peanut.article', int $page = 1) => $workspace->textRoutes($actor, $consumer, $page);
    check(array_column($routes()['list'], 'id') === [$route] && $routes()['page_size'] === 50 && $routes('custom.another')['count'] === 0, 'usable route discovery');
    check($routes('peanut.article',2)['list'] === [] && $routes('peanut.article',2)['count'] === 1, 'route pagination');
    $run = fn(array $changes=[]) => $public->generateText(array_replace($request,$changes));
    denied('AI_CONFIRM_SEND_REQUIRED', fn()=> $run(['confirm_send'=>false]));
    denied('AI_INPUT_INVALID', fn()=> $run(['document_id'=>123]));
    denied('AI_ROUTE_SCOPE_DENIED', fn()=> $run(['consumer_module'=>'custom.another']));
    $auth->allowed = false;
    denied('AI_PERMISSION_DENIED', fn()=> $run());
    denied('AI_PERMISSION_DENIED', fn()=> $routes());
    $auth->allowed = true;
    check($transport->calls===0,'preflight never sent');
    $transport->inFlight = fn()=>denied('AI_UNKNOWN_NO_RESEND', fn()=> $run());
    $candidate = $run();
    $transport->inFlight = null;
    check(strlen($candidate['text'])>0 && $run()===$candidate && $transport->calls===1,'original candidate, single send and replay');
    denied('AI_REQUEST_CONFLICT', fn()=> $run(['outline'=>'不同的提纲']));
    $auth->allowed=false;
    denied('AI_PERMISSION_DENIED', fn()=> $run());
    $auth->allowed=true;
    $record = Db::table('ai_assistant_text_generation')->where('id',$candidate['candidate_id'])->find();
    $d = json_decode($record['details'],true,64,JSON_THROW_ON_ERROR);
    check($record['candidate']===$candidate['text'] && $d['quota']['state']==='confirmed','persisted candidate and settled quota');
    $report = $candidate + ['usage'=>$d['usage'],'http_status'=>$transport->status,'provider_request_id'=>$transport->trace,'provider_calls'=>$transport->calls];
    if ($live) {
        $encoded = json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        check(!str_contains($encoded,$key),'receipt must be secret-free');
        fwrite($resultFile,$encoded."\n"); fclose($resultFile);
        echo "PASS live generateText, confirmed receipt and one provider POST\n";
        return;
    }
    foreach (['rejected'=>'AI_PROVIDER_REJECTED','unknown'=>'AI_UNKNOWN_NO_RESEND','bad_json'=>'AI_PROVIDER_RESPONSE_UNUSABLE'] as $mode=>$code) {
        $transport->mode=$mode;
        $n=$transport->calls;
        for($i=0;$i<2;$i++) denied($code,fn()=> $run(['request_id'=>'dev-'.$mode]));
        check($transport->calls===$n+1,'failed request was sent twice');
    }
    $transport->mode='candidate';
    $route2=$configure('anthropic_messages','https://dev-messages.example.invalid','/api','dev-messages','two');
    check($run(['route_id'=>$route2,'request_id'=>'dev-other-model'])['text']==='候选乙','second protocol and candidate');
    check(Db::table('ai_assistant_quota')->sum('confirmed_requests')==4 && Db::table('ai_assistant_quota')->sum('unknown_requests')==1,'quota evidence');
    $fixed = $config->snapshot($actor, $route);
    $connection = $fixed['connection'];
    $previous = $connection['details'];
    unset($previous['credential_configured'], $previous['last_reason']);
    $changed = $config->save($actor, 'connection', $connection['id'], $connection['version'], 'DEV changed origin',
        array_replace($previous,['origin'=>'https://dev-new.example.invalid']), 'dev-change-origin');
    check($changed['status']==='disabled' && $changed['details']['credential_configured']===false
        && Db::table('ai_assistant_config')->where('id',$connection['id'])->value('secret_payload')===null,'credential detached after origin change');
    denied('AI_CONFIGURATION_DISABLED',fn()=> $run());
    denied('AI_CREDENTIAL_REQUIRED',fn()=> $config->state($actor,'connection',$changed['id'],$changed['version'],'enable','dev-enable-unbound','DEV'));
    check(array_column($routes()['list'],'id') === [$route2], 'disabled connection hides its route');
    check($transport->calls===5,'revoked connection never sent');
    echo "PASS isolated generateText, actor permission, consent, route, reentrant duplicate, replay, provider failures, quota, revocation and two protocols\n";
});
