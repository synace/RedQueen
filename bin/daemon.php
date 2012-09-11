<?php
require_once __DIR__ . '/../vendor/autoload.php';

$app = new Silex\Application();

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver' => 'pdo_sqlite',
        'path' => __DIR__ . '/../data/redqueen.db',
    ),
));

$context = new ZMQContext();

$subscriber = new ZMQSocket($context, ZMQ::SOCKET_SUB);
$subscriber->connect('tcp:///tmp/redqueen.ipc');
$subscriber->setsockopt(ZMQ::SUBSCRIBE, '');

$requester = new ZMQSocket($context, ZMQ::SOCKET_REQ);
$requester->connect('ipc:///tmp/redqueen.ipc');

while(true) {
    $reply = $subscriber->recv();

    $pubd = json_decode($reply);
    $pubd_payload = explode(':', $pubd['payload']);
    $pubd_payload['code'] = $pubd_payload[0];
    $pubd_payload['pin'] = $pubd_payload[1];

    $card = $app['db']->fetchAssoc('SELECT code FROM card WHERE code = ? AND pin = ?', array($pubd_payload['code'], md5($pubd_payload['code'] . $pubd_payload['pin'])));

    if ($card && $card['code'] == $pubd_payload['code']) {
        $open = json_encode(array(
            'address' => $pubd['address'],
            'payload' => 0x02 . pack("N*", 5)
        ));
        $requester->send($open);
    }
}

/*
PUB:
  address: string (hex), in our db
  payload: mixed

REQUEST:
  address: string (hex), in our db
  payload: mixed

  RCV: 1 or 0
*/

/**
 * keypads send:
 * card-id(sixchar hex string):entry(varchar 20), ex:
 *   01234A:01201912391230938
 *
 * card-id(sixchar hex string):entry(varchar 20), ex:
 *   01234A:1234
 *
 * lock
 * {
 *   address: 01234A
 *   payload: 0x00
 * }
 *
 * unlock
 * {
 *   address: 01234A
 *   payload: 0x01
 * }
 *
 * open
 * {
 *   address: 01234A
 *   payload: 0x02 . pack("N*", 9999)
 * }
 *
 */