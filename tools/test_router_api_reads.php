<?php
require_once __DIR__ . '/../classes/MikrotikAPI.php';
require_once __DIR__ . '/../classes/RouterOSAPI.php';
function word($text) {
    $length = strlen($text);
    return ($length < 128 ? chr($length) : chr(0x80 | ($length >> 8)) . chr($length & 255)) . $text;
}
$checks = 0;
foreach (['MikrotikAPI', 'RouterOSAPI'] as $class) {
    foreach ([
        [word('!re').word('=name=example').word('').word('!done').word(''), true],
        [word('!done'), true],
        [word('!re').word('=name=partial').word(''), false],
        [chr(0x80), false],
        [chr(10).'short', false],
        [chr(0xF1), false],
        [chr(0xF0).pack('N', 17000000), false],
    ] as [$bytes, $success]) {
        $api = $class === 'MikrotikAPI' ? new $class('unused', 'unused', 'unused') : new $class();
        $socket = fopen('php://temp', 'w+'); fwrite($socket, $bytes); rewind($socket);
        $property = new ReflectionProperty($class, 'socket'); $property->setValue($api, $socket);
        $method = new ReflectionMethod($class, 'read');
        try { $result = $method->invoke($api); $passed = true; }
        catch (Exception $e) { $passed = false; }
        if ($passed !== $success) throw new Exception($class . ': unexpected read result');
        $api->disconnect(); $checks++;
    }
    $api = $class === 'MikrotikAPI' ? new $class('unused', 'unused', 'unused') : new $class();
    $socket = fopen('php://temp', 'w+');
    (new ReflectionProperty($class, 'socket'))->setValue($api, $socket);
    (new ReflectionProperty($class, 'readDeadline'))->setValue($api, microtime(true)-1);
    try { (new ReflectionMethod($class, 'readExact'))->invoke($api, 1); throw new LogicException('Expired deadline accepted'); }
    catch (LogicException $e) { throw $e; }
    catch (Exception $e) { if (!str_contains($e->getMessage(), 'deadline')) throw $e; }
    $checks++;
}
echo $checks . " router API read checks passed\n";
