<?php
require __DIR__ . '/bootstrap.php';
use Mlab\GiftCard\Crypto;
use Mlab\GiftCard\GiftCardException;
use Mlab\GiftCard\Service;
use Mlab\GiftCard\Storage;

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = new Storage(new GiftCardTestDb(giftCardTestConnection()), $input['prefix']);
$service = new Service($db, new Crypto(str_repeat('a', 64)), 1);
try {
    $result = $service->execute('reserve', $input['payload'], $input['key'], 'test');
    echo json_encode(['ok' => true, 'reservation' => $result['reservation']['id']], JSON_THROW_ON_ERROR);
} catch (GiftCardException $error) {
    echo json_encode(['ok' => false, 'error' => $error->error], JSON_THROW_ON_ERROR);
}
