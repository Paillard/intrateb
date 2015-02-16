<?php

/**
 * Module de gestion des utilisateurs
 * Ce module permet de gérer son compte d'utilisateur ainsi que les utilisateurs
 * du site.
 * @package Epicenote
 */
/* * ******************************* */

/*
 * Experimental : Google Sync
 */

function user_sync() {
    global $config;

    $url = "https://accounts.google.com/o/oauth2/auth";

    $params = array(
        "response_type" => "code",
        "client_id" => $config['GoogleApps']['clientID'],
        "redirect_uri" => $config['GoogleApps']['returnUrl'],
        "scope" => "https://www.googleapis.com/auth/admin.directory.group"
    );

    $request_to = $url . '?' . http_build_query($params);

    header("Location: " . $request_to);
    quit();
}

function user_syncReturn() {
    global $config;

    if (isset($_GET['code'])) {
        // try to get an access token
        $code = $_GET['code'];
        $url = 'https://accounts.google.com/o/oauth2/token';
        $params = array(
            "code" => $code,
            "client_id" => $config['GoogleApps']['clientID'],
            "client_secret" => $config['GoogleApps']['secret'],
            "redirect_uri" => $config['GoogleApps']['returnUrl'],
            "grant_type" => "authorization_code"
        );

        $request = curl_init($url);
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, $params);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($request);
        $responseObj = json_decode($body);

        $_SESSION['user']['GoogleToken'] = $responseObj->access_token;
        redirect('user', 'syncProc');
    }
}

function user_syncProc() {
    global $tpl;

    $ch = curl_init('https://www.googleapis.com/admin/directory/v1/groups/membres@epitanime.com/members?maxResults=1000&oauth_token=' . $_SESSION['user']['GoogleToken']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $members = json_decode(curl_exec($ch));
    $Gregistred = array();
    $Sregistred = array();

    foreach ($members->members as $member) {
        if (isset($member->email)) {
            $Gregistred[] = strtolower($member->email);
        }
    }

    $mdl = new Modele('users');
    $mdl->find();
    while ($mdl->next()) {
        $Sregistred[] = strtolower($mdl->user_email);
    }

    $tpl->assign('add', array_diff($Sregistred, $Gregistred));
    $tpl->assign('del', array_diff($Gregistred, $Sregistred));
    display();
}

/* * ******************************* */

/**
 * Permet d'afficher la liste des utilisateurs
 * @global type $pdo
 * @global type $tpl
 */
function user_index() {
    global $pdo, $tpl;

    if (isset($_POST['search'])) {
        header('location: ' . urldup(array(
                    'search' => $_POST['search'],
        )));
        quit();
    }

    $where = '';

    if (isset($_GET['search'])) {
        $where = 'WHERE user_name LIKE ? '
                . 'OR user_lastname LIKE ? '
                . 'OR user_firstname LIKE ? '
                . 'OR user_email LIKE ? ';
    }

    $pager = new SimplePager('users', $where . 'ORDER BY user_name ASC', 'p', 20);

    if (isset($_GET['search'])) {
        $pager->bindValue(1, "%${_GET['search']}%");
        $pager->bindValue(2, "%${_GET['search']}%");
        $pager->bindValue(3, "%${_GET['search']}%");
        $pager->bindValue(4, "%${_GET['search']}%");
    }

    $pager->run($tpl);
    $tpl->display('user_index.tpl');
    quit();
}

/**
 * Ajoute un utilisateur
 * Des fois c'est bien de pouvoir rajouter un utilisateur depuis le panneau d'admin pour l'ajout des nouveaux adhérents.
 */
function user_add() {
    global $pdo, $tpl;

    $tpl->assign('error', false);
    $tpl->assign('succes', false);

    if (isset($_POST['user_name'])) {
        if (autoInsert('users', 'user_')) {
            $tpl->assign('succes', true);
        } else
            $tpl->assign('error', true);
    }

    $sql = $pdo->prepare('SELECT * FROM user_types');
    $sql->execute();
    while ($type = $sql->fetch()) {
        $tpl->append('types', $type);
    }

    $tpl->display('user_add.tpl');
    quit();
}

/**
 * Suppresion d'un utilisateur
 */
function user_delete() {
    global $pdo;

    $sql = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
    $sql->bindValue(1, $_GET['user']);
    if ($sql->execute())
        redirect('user');
    else
        modexec('syscore', 'sqlerror');
}

/**
 * Détails d'un utilisateur
 * Et optionnellement sa vie.
 */
function user_view() {
    global $pdo, $tpl;

    $sql = $pdo->prepare('SELECT * FROM users LEFT JOIN user_types ON ut_id = user_type WHERE user_id = ?');
    $sql->bindValue(1, $_REQUEST['user']);
    $sql->execute();
    $user = $sql->fetch();
    $tpl->assign('user', $user);

    $sql = $pdo->prepare('SELECT * FROM user_sections LEFT JOIN sections ON section_id = us_section WHERE us_user = ?');
    $sql->bindValue(1, $user['user_id']);
    $sql->execute();
    $sections = array();
    while ($line = $sql->fetch()) {
        $sections[] = $line['section_id'];
        $tpl->append('sections', $line);
    }

    $sql = $pdo->prepare('SELECT * FROM sections WHERE section_type = "primary"');
    $sql->execute();
    while ($line = $sql->fetch())
        if (!in_array($line['section_id'], $sections))
            $tpl->append('section_list', $line);

    $mdt = new Modele('user_mandate');
    $mdt->find(array('um_user' => $_REQUEST['user']));
    while ($mdt->next()) {
        $tpl->append('mandates', $mdt->um_mandate);
    }

    $tpl->display('user_details.tpl');
    quit();
}

/**
 * Ajoute un utilisateur comme staff d'une section
 * Cette fonctionnalité permet de gérer les sections d'un utilisateur directement depuis son compte :p
 */
function user_invit_section() {
    global $pdo;

    $sql = $pdo->prepare('INSERT INTO user_sections (us_user, us_section, us_type) VALUES (?, ?, "user")');
    $sql->bindValue(1, $_GET['user']);
    $sql->bindValue(2, $_POST['us_section']);
    $sql->execute();
    redirect('user', 'view', array('user' => $_GET['user']));
}

/**
 * Permet de quitter une section
 */
function user_quit() {
    global $pdo;

    $sql = $pdo->prepare('DELETE FROM user_sections WHERE us_user = ? AND us_section = ?');
    $sql->bindValue(1, $_GET['user']);
    $sql->bindValue(2, $_GET['section']);
    $sql->execute();
    redirect('user', 'view', array('user' => $_GET['user']));
}

function user_add_mandate($user, $mandate) {
    $usr = new Modele('users');
    $mdt = new Modele('mandate');
    $lnk = new Modele('user_mandate');

    if (preg_match('/^9([0-9]{4})([0-9]{7})[0-9]$/', $user, $matchs)) {
        $user = $matchs[2];
        $mandate = $matchs[1];
    }

    $usr->fetch($user);
    $mdt->fetch($mandate);

    if ($lnk->find(array(
                'um_user' => $usr->getKey(),
                'um_mandate' => $mdt->getKey(),
            )) && $lnk->count() > 0) {
        return true;
    }

    $succ = $lnk->addFrom(array(
        'um_user' => $usr->getKey(),
        'um_mandate' => $mdt->getKey(),
    ));

    if ($succ && (aclFromText($usr->raw_user_role) < ACL_USER)) {
        $usr->user_role = ACL_USER;
    }

    return $succ;
}

function user_check() {
    global $tpl;

    $mdt = new Modele('mandate');
    $mdt->find();

    $tpl->assign('mandates', array());

    if (isset($_POST['idfiche'])) {
        $tpl->assign('hsuccess', user_add_mandate($_POST['idfiche'], $_POST['mandate']));
    }

    while ($l = $mdt->next()) {
        $tpl->append('mandates', $l);
    }

    display();
}

function user_editpassword() {
    global $tpl;

    $pass = $_POST['password'];
    $confirm = $_POST['password2'];
    $user = $_GET['user'];

    if ($pass != $confirm) {
        $tpl->assign('hsuccess', false);
    } else {
        $mdl = new Modele('users');
        $mdl->fetch($user);

        $rslt = $mdl->modFrom(array(
            'user_pass' => md5($mdl->user_name . ':' . $pass),
                ), false);

        $tpl->assign('hsuccess', $rslt);

        modexec('user', 'view');
    }
}
