<?php

require_once(dirname(__file__).'/lib/jasig/phpcas/CAS.php');

class CasAuth {
    private $config;

    function __construct($config) {
        $this->config = $config;
    }

    function triggerAuth($service_url = false) {
        $self = $this;
        phpCAS::client(
          CAS_VERSION_2_0,
          $this->config->get('cas-hostname'),
          intval($this->config->get('cas-port')),
          $this->config->get('cas-context')
        );

        // Force set the CAS service URL to the osTicket login page.
        if ($service_url) {
          phpCAS::setFixedServiceURL($this->service_url);
        }

        // Verify the CAS server's certificate, if configured.
        if($this->config->get('cas-ca-cert-path')) {
            phpCAS::setCasServerCACert($this->config->get('cas-ca-cert-path'));
        } else {
            phpCAS::setNoCasServerValidation();
        }

        // Trigger authentication and set the user fields when validated.
        if(!phpCAS::isAuthenticated()) {
            phpCAS::forceAuthentication();
        } else {
            $this->setUser();
            $this->setEmail();
            $this->setName();
        }
    }

    function setUser() {
        $_SESSION[':cas']['user'] = phpCAS::getUser();
    }

    function getUser() {
        return $_SESSION[':cas']['user'];
    }

    function setEmail() {
        if($this->config->get('cas-email-attribute-key') !== null
            && phpCAS::hasAttribute($this->config->get('cas-email-attribute-key'))) {
            $_SESSION[':cas']['email'] = phpCAS::getAttribute(
              $this->config->get('cas-email-attribute-key'));
        } else {
            $email = $this->getUser();
            if($this->config->get('cas-at-domain') !== null) {
                $email .= $this->config->get('cas-at-domain');
            }
            $_SESSION[':cas']['email'] = $email;
        }
    }

    function getEmail() {
        return $_SESSION[':cas']['email'];
    }

    function setName() {
        if($this->config->get('cas-name-attribute-key') !== null
            && phpCAS::hasAttribute($this->config->get('cas-name-attribute-key'))) {
            $_SESSION[':cas']['name'] = phpCAS::getAttribute(
              $this->config->get('cas-name-attribute-key'));
        } else {
            $_SESSION[':cas']['name'] = $this->getUser();
        }
    }

    function getName() {
        return $_SESSION[':cas']['name'];
    }

    function getProfile() {
        return array(
            'email' => $this->getEmail(),
            'name' => $this->getName()
        );
    }
}

class CasStaffAuthBackend extends ExternalStaffAuthenticationBackend {
    static $id = "cas";
    static $name = /* trans */ "CAS";

    static $service_name = "CAS";

    var $config;

    function __construct($config) {
        $this->config = $config;
        $this->cas = new CasAuth($config);
    }

    function getName() {
         $config = $this->config;
         list($__, $_N) = $config::translate();
         return $__(static::$name);
     }

    function signOn() {
        if (isset($_SESSION[':cas']['user'])) {
            $staff = new StaffSession($this->cas->getEmail());
            if ($staff && $staff->getId()) {
                return $staff;
            } else {
                $_SESSION['_staff']['auth']['msg'] = 'Have your administrator create a local account';
            }
        }
    }

    static function signOut($user) {
        parent::signOut($user);
        unset($_SESSION[':cas']);
    }

    function getServiceUrl() {
      global $cfg;

      if (!$cfg) {
        return null;
      }
      return $cfg->getUrl() . "scp/";
    }

    function triggerAuth() {
        parent::triggerAuth();
        $cas = $this->cas->triggerAuth($this->getServiceUrl());
        Http::redirect("scp/");
    }
}

class CasClientAuthBackend extends ExternalUserAuthenticationBackend {
    static $id = "cas.client";
    static $name = /* trans */ "CAS";

    static $service_name = "CAS";

    function __construct($config) {
        $this->config = $config;
        $this->cas = new CasAuth($config);
    }

    function getName() {
         $config = $this->config;
         list($__, $_N) = $config::translate();
         return $__(static::$name);
     }

    function supportsInteractiveAuthentication() {
        return false;
    }

    function signOn() {
        if (isset($_SESSION[':cas']['user'])) {
            $acct = ClientAccount::lookupByUsername($this->cas->getEmail());
            $client = null;
            if ($acct && $acct->getId()) {
                $client = new ClientSession(new EndUser($acct->getUser()));
            }

            if ($client) {
                return $client;
            } else {
                return new ClientCreateRequest(
                  $this, $this->cas->getEmail(), $this->cas->getProfile());
            }
        }
    }

    static function signOut($user) {
        parent::signOut($user);
        unset($_SESSION[':cas']);
    }

    function getServiceUrl() {
      global $cfg;
      if (!$cfg) {
        return null;
      }
      return $cfg->getUrl() . "login.php";
    }

    function triggerAuth() {
        parent::triggerAuth();
        $cas = $this->cas->triggerAuth($this->getServiceUrl());
        Http::redirect("login.php");
    }
}
