<?php
require_once __DIR__ . '/../vendor/autoload.php';
$app = new Silex\Application();

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver' => 'pdo_sqlite',
        'path' => __DIR__ . '/../data/redqueen.db',
    ),
));

$schema = $app['db']->getSchemaManager();
if (!$schema->tablesExist('member')) {
    $members = new Doctrine\DBAL\Schema\Table('member');
    $members->addColumn('id', 'integer', array('autoincrement' => true));
    $members->setPrimaryKey(array('id'));
    $members->addColumn('name', 'string', array('length' => 255));
    $members->addColumn('gender', 'string', array('length' => 1));
    $members->addColumn('username', 'string', array('length' => 255));
    $members->addUniqueIndex(array('username'));
    $members->addColumn('email', 'string', array('length' => 255));
    $members->addUniqueIndex(array('email'));
    $members->addColumn('password', 'string', array('length' => 255));
    $members->addColumn('roles', 'string', array('length' => 255));
    $schema->createTable($members);

    $cards = new Doctrine\DBAL\Schema\Table('card');
    $cards->addColumn('id', 'integer', array('autoincrement' => true));
    $cards->setPrimaryKey(array('id'));
    $cards->addColumn('code', 'string', array('length' => 6));
    $cards->addColumn('member_id', 'integer', array('unsigned' => true));
    $cards->addColumn('pin', 'string', array('length' => 32));
    $cards->addForeignKeyConstraint($members, array('member_id'), array('id'), array('onDelete' => 'CASCADE'));
    $schema->createTable($cards);
}

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'locale' => 'en',
    'locale_fallback' => 'en',
    'translator.messages' => array(),
));
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\FormServiceProvider());

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/../views',
    'twig.options'  => array(
        'debug' => true,
        'cache' => false
    ),
));
$app['twig']->addExtension(new Twig_Extension_Debug());

$app['debug'] = true;

//$app->register(new Silex\Provider\SecurityServiceProvider());

//$app['security.firewalls'] = array(
//    'admin' => array(
//        'pattern' => '^/',
//        'http' => true,
//        'users' => array(
//            // raw password is foo
//            'admin' => array('ROLE_ADMIN', '5FZ2Z8QIkA7UTZ4BYkoC+GsReLf569mSKDsfods6LYQ8t+a8EW9oaircfMpmaLbPBh4FOBiiFyLfuZmTSUwzZg=='),
//        ),
//    ),
//);

////////////////////////////////////////////////////////////////////////////////

$app->get('/', function () use ($app) {
    return $app->redirect($app['url_generator']->generate('members'));
})->bind('homepage');

$app->get('/members', function () use ($app) {
    $members = $app['db']->fetchAll('SELECT * FROM member');
    return $app['twig']->render('members.twig', array(
        'members' => $members,
    ));
})->bind('members');

$app->match('/members/add', function (Symfony\Component\HttpFoundation\Request $request) use ($app) {
    $member = array(
        'name' => '',
        'cards' => array()
    );
    $form = RedQueen::MemberForm($app, $request, $member, true);
    if ($form instanceOf Symfony\Component\HttpFoundation\Response) {
        return $form;
    }
    return $app['twig']->render('member.twig', array('member' => $member, 'form' => $form->createView()));
})->bind('members_add');

$app->match('/members/edit/{id}', function (Symfony\Component\HttpFoundation\Request $request, $id) use ($app) {
    $member = $app['db']->fetchAssoc('SELECT * FROM member WHERE id = ?', array($id));
    $member['cards'] = $app['db']->fetchAll('SELECT * FROM card c WHERE c.member_id = ?', array($id));
    $form = RedQueen::MemberForm($app, $request, $member, false);
    if ($form instanceOf Symfony\Component\HttpFoundation\Response) {
        return $form;
    }
    return $app['twig']->render('member.twig', array('member' => $member, 'form' => $form->createView()));
})->bind('members_edit');

$app->match('/members/add_card/{id}', function (Symfony\Component\HttpFoundation\Request $request, $id) use ($app) {
    $member = $app['db']->fetchAssoc('SELECT * FROM member WHERE id = ?', array($id));
    $card = array('member_id' => $id);
    $form = RedQueen::CardForm($app, $request, $card, true);
    if ($form instanceOf Symfony\Component\HttpFoundation\Response) {
        return $form;
    }
    return $app['twig']->render('card.twig', array('member' => $member, 'card' => $card, 'form' => $form->createView()));
})->bind('members_add_card');

$app->match('/members/edit_card/{id}', function (Symfony\Component\HttpFoundation\Request $request, $id) use ($app) {
    $card = $app['db']->fetchAssoc('SELECT * FROM card WHERE id = ?', array($id));
    $member = $app['db']->fetchAssoc('SELECT * FROM member WHERE id = ?', array($card['member_id']));
    $form = RedQueen::CardForm($app, $request, $card, false);
    if ($form instanceOf Symfony\Component\HttpFoundation\Response) {
        return $form;
    }
    return $app['twig']->render('card.twig', array('member' => $member, 'card' => $card, 'form' => $form->createView()));
})->bind('members_edit_card');

$app->match('/members/delete_card/{id}', function (Symfony\Component\HttpFoundation\Request $request, $id) use ($app) {
    $card = $app['db']->fetchAssoc('SELECT * FROM card WHERE id = ?', array($id));
    $member = $app['db']->fetchAssoc('SELECT * FROM member WHERE id = ?', array($card['member_id']));
    $title = $card['code'] . ' for ' . $member['name'];
    $form = RedQueen::ConfirmForm($app, $request);
    if (is_array($form) && $form['confirm']) {
        $app['db']->delete('card', array('id' => $id));
        return $app->redirect($app['url_generator']->generate('members_edit', array('id' => $card['member_id'])));
    }
    return $app['twig']->render('confirm.twig', array('title' => $title, 'form' => $form->createView()));
})->bind('members_delete_card');

$app->match('/members/delete/{id}', function (Symfony\Component\HttpFoundation\Request $request, $id) use ($app) {
    $member = $app['db']->fetchAssoc('SELECT * FROM member WHERE id = ?', array($card['member_id']));
    $title = $member['name'];
    $form = RedQueen::ConfirmForm($app, $request);
    if (is_array($form) && $form['confirm']) {
        $app['db']->delete('member', array('id' => $id));
        return $app->redirect($app['url_generator']->generate('members'));
    }
    return $app['twig']->render('confirm.twig', array('title' => $title, 'form' => $form->createView()));
})->bind('members_delete');

$app->run();

class RedQueen {
    static function MemberForm($app, $request, $member, $insert = false) {
        $form = $app['form.factory']->createBuilder('form', $member)
            ->add('name')
            ->add('username')
            ->add('password', 'password')
            ->add('email')
            ->add('roles')
            ->add('gender', 'choice', array(
                'choices' => array('m' => 'male', 'f' => 'female'),
                'expanded' => true,
            ))
            ->getForm();
        if ('POST' == $request->getMethod()) {
            $form->bind($request);
            if ($form->isValid()) {
                $data = $form->getData();
                if ($insert) {
                    $app['db']->insert('member', array('name' => $data['name'], 'email' => $data['email'], 'username' => $data['username'], 'password' => md5($data['username'] . $data['password']), 'gender' => $data['gender'], 'roles' => $data['roles']));
                    $id = $app['db']->lastInsertId();
                } else {
                    if ($data['password']) {
                        $app['db']->update('member', array('name' => $data['name'], 'email' => $data['email'], 'username' => $data['username'], 'password' => md5($data['username'] . $data['password']), 'gender' => $data['gender'], 'roles' => $data['roles']), array('id' => $data['id']));
                    } else {
                        $app['db']->update('member', array('name' => $data['name'], 'email' => $data['email'], 'username' => $data['username'], 'gender' => $data['gender'], 'roles' => $data['roles']), array('id' => $data['id']));
                    }
                    $id = $member['id'];
                }
                return $app->redirect($app['url_generator']->generate('members_edit', array('id' => $id)));
            }
        }
        return $form;
    }
    static function CardForm($app, $request, $card, $insert = false) {
        $card['pin'] = '';
        $form = $app['form.factory']->createBuilder('form', $card)
            ->add('member_id', 'hidden')
            ->add('code')
            ->add('pin')
            ->getForm();
        if ('POST' == $request->getMethod()) {
            $form->bind($request);
            if ($form->isValid()) {
                $data = $form->getData();
                if ($insert) {
                    $app['db']->insert('card', array('code' => $data['code'], 'member_id' => $data['member_id'], 'pin' => md5($data['code'] . $data['pin'])));
                    $id = $app['db']->lastInsertId();
                } else {
                    $app['db']->update('card', array('pin' => md5($data['code'] . $data['pin'])), array('id', $card['id']));
                    $id = $card['id'];
                }
                return $app->redirect($app['url_generator']->generate('members_edit', array('id' => $id)));
            }
        }
        return $form;
    }
    static function ConfirmForm($app, $request) {
        $data = array('confirm' => 0);
        $form = $app['form.factory']->createBuilder('form', $data)
            ->add('confirm', 'hidden')
            ->getForm();
        if ('POST' == $request->getMethod()) {
            $form->bind($request);
            $data = $form->getData();
            if ($form->isValid() && $data['confirm']) {
                return $data;
            }
        }
        return $form;
    }
}