<?php

namespace SMTPValidateEmail;

use \SMTPValidateEmail\Exceptions\Exception as Exception;
use \SMTPValidateEmail\Exceptions\Timeout as TimeoutException;
use \SMTPValidateEmail\Exceptions\NoTimeout as NoTimeoutException;
use \SMTPValidateEmail\Exceptions\NoConnection as NoConnectionException;
use \SMTPValidateEmail\Exceptions\UnexpectedResponse as UnexpectedResponseException;
use \SMTPValidateEmail\Exceptions\NoHelo as NoHeloException;
use \SMTPValidateEmail\Exceptions\NoMailFrom as NoMailFromException;
use \SMTPValidateEmail\Exceptions\NoResponse as NoResponseException;
use \SMTPValidateEmail\Exceptions\SendFailed as SendFailedException;

class Validator
{

    public $log = [];

    /**
     * Print stuff as it happens or not
     *
     * @var bool
     */
    public $debug = false;

    /**
     * Default smtp port to connect to
     *
     * @var int
     */
    public $connect_port = 25;

    /**
     * Are "catch-all" accounts considered valid or not?
     * If not, the class checks for a "catch-all" and if it determines the box
     * has a "catch-all", sets all the emails on that domain as invalid.
     *
     * @var bool
     */
    public $catchall_is_valid = true;

    /**
     * Whether to perform the "catch-all" test or not
     *
     * @var bool
     */
    public $catchall_test = false; // Set to true to perform a catchall test

    /**
     * Being unable to communicate with the remote MTA could mean an address
     * is invalid, but it might not, depending on your use case, set the
     * value appropriately.
     *
     * @var bool
     */
    public $no_comm_is_valid = false;

    /**
     * Being unable to connect with the remote host could mean a server
     * configuration issue, but it might not, depending on your use case,
     * set the value appropriately.
     */
    public $no_conn_is_valid = false;

    /**
     * Whether "greylisted" responses are considered as valid or invalid addresses
     *
     * @var bool
     */
    public $greylisted_considered_valid = true;

    /**
     * Stream context arguments for connection socket, necessary to initiate
     * Server IP (in case reverse IP), see: https://stackoverflow.com/a/8968016
     */
    public $stream_context_args = [];

    /**
     * Timeout values for various commands (in seconds) per RFC 2821
     *
     * @var array
     */
    protected $command_timeouts = [
        'ehlo' => 120,
        'helo' => 120,
        'tls'  => 180, // start tls
        'mail' => 300, // mail from
        'rcpt' => 300, // rcpt to,
        'rset' => 30,
        'quit' => 60,
        'noop' => 60
    ];

    /**
     * Whether NOOP commands are sent at all.
     *
     * @var bool
     */
    protected $send_noops = true;

    const CRLF = "\r\n";

    // Some smtp response codes
    const SMTP_CONNECT_SUCCESS = 220;
    const SMTP_QUIT_SUCCESS    = 221;
    const SMTP_GENERIC_SUCCESS = 250;
    const SMTP_USER_NOT_LOCAL  = 251;
    const SMTP_CANNOT_VRFY     = 252;

    const SMTP_SERVICE_UNAVAILABLE = 421;

    // 450 Requested mail action not taken: mailbox unavailable (e.g.,
    // mailbox busy or temporarily blocked for policy reasons)
    const SMTP_MAIL_ACTION_NOT_TAKEN = 450;
    // 451 Requested action aborted: local error in processing
    const SMTP_MAIL_ACTION_ABORTED = 451;
    // 452 Requested action not taken: insufficient system storage
    const SMTP_REQUESTED_ACTION_NOT_TAKEN = 452;

    // 500 Syntax error (may be due to a denied command)
    const SMTP_SYNTAX_ERROR = 500;
    // 502 Comment not implemented
    const SMTP_NOT_IMPLEMENTED = 502;
    // 503 Bad sequence of commands (may be due to a denied command)
    const SMTP_BAD_SEQUENCE = 503;

    // 550 Requested action not taken: mailbox unavailable (e.g., mailbox
    // not found, no access, or command rejected for policy reasons)
    const SMTP_MBOX_UNAVAILABLE = 550;

    // 554 Seen this from hotmail MTAs, in response to RSET :(
    const SMTP_TRANSACTION_FAILED = 554;

    /**
     * List of response codes considered as "greylisted"
     *
     * @var array
     */
    private $greylisted = [
        self::SMTP_MAIL_ACTION_NOT_TAKEN,
        self::SMTP_MAIL_ACTION_ABORTED,
        self::SMTP_REQUESTED_ACTION_NOT_TAKEN
    ];

    /**
     * Internal states we can be in
     *
     * @var array
     */
    private $state = [
        'helo' => false,
        'mail' => false,
        'rcpt' => false
    ];

    /**
     * Holds the socket connection resource
     *
     * @var resource
     */
    private $socket;

    /**
     * Holds all the domains we'll validate accounts on
     *
     * @var array
     */
    private $domains = [];

    /**
     * @var array
     */
    private $domains_info = [];

    /**
     * Default connect timeout for each MTA attempted (seconds)
     *
     * @var int
     */
    private $connect_timeout = 10;

    /**
     * Default sender username
     *
     * @var string
     */
    private $from_user = 'user';

    /**
     * Default sender host
     *
     * @var string
     */
    private $from_domain = 'localhost';

    /**
     * The host we're currently connected to
     *
     * @var string|null
     */
    private $host = null;

    /**
     * List of validation results
     *
     * @var array
     */
    private $results = [];

    /**
     * @param array|string $emails Email(s) to validate
     * @param string|null $sender Sender's email address
     */
    public function __construct($emails = [], $sender = null)
    {
        if (!empty($emails)) {
            $this->setEmails($emails);
        }
        if (null !== $sender) {
            $this->setSender($sender);
        }
    }

    /**
     * Disconnects from the SMTP server if needed to release resources.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    public function __destruct()
    {
        $this->disconnect(false);
    }

    /**
     * Does a catch-all test for the given domain.
     *
     * @param string $domain
     *
     * @return bool Whether the MTA accepts any random recipient.
     *
     * @throws NoConnectionException
     * @throws NoMailFromException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    public function acceptsAnyRecipient($domain)
    {
        if (!$this->catchall_test) {
            return false;
        }

        $test     = 'catch-all-test-' . time();
        $accepted = $this->rcpt($test . '@' . $domain);
        if ($accepted) {
            // Success on a non-existing address is a "catch-all"
            $this->domains_info[$domain]['catchall'] = true;
            return true;
        }

        // Log when we get disconnected while trying catchall detection
        $this->noop();
        if (!$this->connected()) {
            $this->debug('Disconnected after trying a non-existing recipient on ' . $domain);
        }

        /**
         * N.B.:
         * Disconnects are considered as a non-catch-all case this way, but
         * that might not always be the case.
         */
        return false;
    }

    /**
     * Performs validation of specified email addresses.
     *
     * @param array|string $emails Emails to validate (or a single one as a string).
     * @param string|null $sender Sender email address.
     *
     * @return array List of emails and their results.
     *
     * @throws NoConnectionException
     * @throws NoHeloException
     * @throws NoMailFromException
     * @throws NoTimeoutException
     * @throws SendFailedException
     */
    public function validate($emails = [], $sender = null)
    {
        $this->results = [];

        if (!empty($emails)) {
            $this->setEmails($emails);
        }
        if (null !== $sender) {
            $this->setSender($sender);
        }

        if (empty($this->domains)) {
            return $this->results;
        }

        $this->loop();

        return $this->getResults();
    }

    /**
     * @throws NoConnectionException
     * @throws NoHeloException
     * @throws NoMailFromException
     * @throws NoTimeoutException
     * @throws SendFailedException
     */
    protected function loop()
    {
        // Query the MTAs on each domain if we have them
        foreach ($this->domains as $domain => $users) {
            $mxs = $this->buildMxs($domain);

            $this->debug('MX records (' . $domain . '): ' . print_r($mxs, true));
            $this->domains_info[$domain]          = [];
            $this->domains_info[$domain]['users'] = $users;
            $this->domains_info[$domain]['mxs']   = $mxs;

            // Set default results as though we can't communicate at all...
            $this->setDomainResults($users, $domain, $this->no_conn_is_valid);
            $this->attemptConnection($mxs);
            $this->performSmtpDance($domain, $users);
        }
    }

    /**
     * @param string $domain
     * @return array
     */
    protected function buildMxs($domain)
    {
        $mxs = [];

        // Query the MX records for the current domain
        list($hosts, $weights) = $this->mxQuery($domain);

        // Sort out the MX priorities
        foreach ($hosts as $k => $host) {
            $mxs[$host] = $weights[$k];
        }
        asort($mxs);

        // Add the hostname itself with 0 weight (RFC 2821)
        $mxs[$domain] = 0;

        return $mxs;
    }

    /**
     * @param array $mxs
     *
     * @throws NoTimeoutException
     */
    protected function attemptConnection(array $mxs)
    {
        // Try each host, $_weight unused in the foreach body, but array_keys() doesn't guarantee the order
        foreach ($mxs as $host => $_weight) {
            try {
                $this->connect($host);
                if ($this->connected()) {
                    break;
                }
            } catch (NoConnectionException $e) {
                // Unable to connect to host, so these addresses are invalid?
                $this->debug('Unable to connect. Exception caught: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param string $domain
     * @param array $users
     *
     * @throws NoConnectionException
     * @throws NoHeloException
     * @throws NoMailFromException
     * @throws SendFailedException
     */
    protected function performSmtpDance($domain, array $users)
    {
        // Bail early if not connected for whatever reason...
        if (!$this->connected()) {
            return;
        }

        try {
            $this->attemptMailCommands($domain, $users);
        } catch (UnexpectedResponseException $e) {
            // Unexpected responses handled as $this->no_comm_is_valid, that way anyone can
            // decide for themselves if such results are considered valid or not
            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
        } catch (TimeoutException $e) {
            // A timeout is a comm failure, so treat the results on that domain
            // according to $this->no_comm_is_valid as well
            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
        }
    }

    /**
     * @param string $domain
     * @param array $users
     *
     * @throws NoConnectionException
     * @throws NoHeloException
     * @throws NoMailFromException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function attemptMailCommands($domain, array $users)
    {
        // Bail if HELO doesn't go through...
        if (!$this->helo()) {
            return;
        }

        // Try issuing MAIL FROM
        if (!$this->mail($this->from_user . '@' . $this->from_domain)) {
            // MAIL FROM not accepted, we can't talk
            $this->setDomainResults($users, $domain, $this->no_comm_is_valid);
            return;
        }

        /**
         * If we're still connected, proceed (cause we might get
         * disconnected, or banned, or greylisted temporarily etc.)
         * see mail() for more
         */
        if (!$this->connected()) {
            return;
        }

        // Attempt a catch-all test for the domain (if configured to do so)
        $is_catchall_domain = $this->acceptsAnyRecipient($domain);

        // If a catchall domain is detected, and we consider
        // accounts on such domains as invalid, mark all the
        // users as invalid and move on
        if ($is_catchall_domain) {
            if (!$this->catchall_is_valid) {
                $this->setDomainResults($users, $domain, $this->catchall_is_valid);
                return;
            }
        }

        $this->noop();

        // RCPT for each user
        foreach ($users as $user) {
            $address                 = $user . '@' . $domain;
            $this->results[$address] = $this->rcpt($address);
        }

        // Issue a RSET for all the things we just made the MTA do
        $this->rset();
        $this->disconnect();
    }

    /**
     * Get validation results
     *
     * @param bool $include_domains_info Whether to include extra info in the results
     *
     * @return array
     */
    public function getResults($include_domains_info = true)
    {
        if ($include_domains_info) {
            $this->results['domains'] = $this->domains_info;
        } else {
            unset($this->results['domains']);
        }

        return $this->results;
    }

    /**
     * Helper to set results for all the users on a domain to a specific value
     *
     * @param array $users Users (usernames)
     * @param string $domain The domain for the users/usernames
     * @param bool $val Value to set
     *
     * @return void
     */
    private function setDomainResults(array $users, $domain, $val)
    {
        foreach ($users as $user) {
            $this->results[$user . '@' . $domain] = $val;
        }
    }

    /**
     * Returns true if we're connected to an MTA
     *
     * @return bool
     */
    protected function connected()
    {
        return is_resource($this->socket);
    }

    /**
     * Tries to connect to the specified host on the pre-configured port.
     *
     * @param string $host Host to connect to
     *
     * @throws NoConnectionException
     * @throws NoTimeoutException
     *
     * @return void
     */
    protected function connect($host)
    {
        $remote_socket = $host . ':' . $this->connect_port;
        $errnum        = 0;
        $errstr        = '';
        $this->host    = $remote_socket;

        // Open connection
        $this->debug('Connecting to ' . $this->host);
        // @codingStandardsIgnoreLine
        $this->socket = /** @scrutinizer ignore-unhandled */ @stream_socket_client(
            $this->host,
            $errnum,
            $errstr,
            $this->connect_timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($this->stream_context_args)
        );

        // Check and throw if not connected
        if (!$this->connected()) {
            $this->debug('Connect failed: ' . $errstr . ', error number: ' . $errnum . ', host: ' . $this->host);
            throw new NoConnectionException('Cannot open a connection to remote host (' . $this->host . ')');
        }

        $result = stream_set_timeout($this->socket, $this->connect_timeout);
        if (!$result) {
            throw new NoTimeoutException('Cannot set timeout');
        }

        $this->debug('Connected to ' . $this->host . ' successfully');
    }

    /**
     * Disconnects the currently connected MTA.
     *
     * @param bool $quit Whether to send QUIT command before closing the socket on our end.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function disconnect($quit = true)
    {
        if ($quit) {
            $this->quit();
        }

        if ($this->connected()) {
            $this->debug('Closing socket to ' . $this->host);
            fclose($this->socket);
        }

        $this->host = null;
        $this->resetState();
    }

    /**
     * Resets internal state flags to defaults
     */
    private function resetState()
    {
        $this->state['helo'] = false;
        $this->state['mail'] = false;
        $this->state['rcpt'] = false;
    }

    /**
     * Sends a HELO/EHLO sequence.
     *
     * @return bool|null True if successful, false otherwise. Null if already done.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function helo()
    {
        // Don't do it if already done
        if ($this->state['helo']) {
            return null;
        }

        try {
            $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['helo']);
            $this->ehlo();

            // Session started
            $this->state['helo'] = true;

            // Are we going for a TLS connection?
            /*
            if ($this->tls) {
                // send STARTTLS, wait 3 minutes
                $this->send('STARTTLS');
                $this->expect(self::SMTP_CONNECT_SUCCESS, $this->command_timeouts['tls']);
                $result = stream_socket_enable_crypto($this->socket, true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$result) {
                    throw new SMTP_Validate_Email_Exception_No_TLS('Cannot enable TLS');
                }
            }
            */

            $result = true;
        } catch (UnexpectedResponseException $e) {
            // Connected, but got an unexpected response, so disconnect
            $result = false;
            $this->debug('Unexpected response after connecting: ' . $e->getMessage());
            $this->disconnect(false);
        }

        return $result;
    }

    /**
     * Sends `EHLO` or `HELO`, depending on what's supported by the remote host.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function ehlo()
    {
        try {
            // Modern
            $this->send('EHLO ' . $this->from_domain);
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['ehlo']);
        } catch (UnexpectedResponseException $e) {
            // Legacy
            $this->send('HELO ' . $this->from_domain);
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['helo']);
        }
    }

    /**
     * Sends a `MAIL FROM` command which indicates the sender.
     *
     * @param string $from
     *
     * @return bool Whether the command was accepted or not.
     *
     * @throws NoConnectionException
     * @throws NoHeloException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function mail($from)
    {
        if (!$this->state['helo']) {
            throw new NoHeloException('Need HELO before MAIL FROM');
        }

        // Issue MAIL FROM, 5 minute timeout
        $this->send('MAIL FROM:<' . $from . '>');

        try {
            $this->expect(self::SMTP_GENERIC_SUCCESS, $this->command_timeouts['mail']);

            // Set state flags
            $this->state['mail'] = true;
            $this->state['rcpt'] = false;

            $result = true;
        } catch (UnexpectedResponseException $e) {
            $result = false;

            // Got something unexpected in response to MAIL FROM
            $this->debug("Unexpected response to MAIL FROM\n:" . $e->getMessage());

            // Hotmail has been known to do this + was closing the connection
            // forcibly on their end, so we're killing the socket here too
            $this->disconnect(false);
        }

        return $result;
    }

    /**
     * Sends a RCPT TO command to indicate a recipient. Returns whether the
     * recipient was accepted or not.
     *
     * @param string $to Recipient (email address).
     *
     * @return bool Whether the address was accepted or not.
     *
     * @throws NoMailFromException
     */
    protected function rcpt($to)
    {
        // Need to have issued MAIL FROM first
        if (!$this->state['mail']) {
            throw new NoMailFromException('Need MAIL FROM before RCPT TO');
        }

        $valid          = false;
        $expected_codes = [
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_USER_NOT_LOCAL
        ];

        if ($this->greylisted_considered_valid) {
            $expected_codes = array_merge($expected_codes, $this->greylisted);
        }

        // Issue RCPT TO, 5 minute timeout
        try {
            $this->send('RCPT TO:<' . $to . '>');
            // Handle response
            try {
                $this->expect($expected_codes, $this->command_timeouts['rcpt']);
                $this->state['rcpt'] = true;
                $valid               = true;
            } catch (UnexpectedResponseException $e) {
                $this->debug('Unexpected response to RCPT TO: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->debug('Sending RCPT TO failed: ' . $e->getMessage());
        }

        return $valid;
    }

    /**
     * Sends a RSET command and resets certain parts of internal state.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function rset()
    {
        $this->send('RSET');

        // MS ESMTP doesn't follow RFC according to ZF tracker, see [ZF-1377]
        $expected = [
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_CONNECT_SUCCESS,
            self::SMTP_NOT_IMPLEMENTED,
            // hotmail returns this o_O
            self::SMTP_TRANSACTION_FAILED
        ];
        $this->expect($expected, $this->command_timeouts['rset'], true);
        $this->state['mail'] = false;
        $this->state['rcpt'] = false;
    }

    /**
     * Sends a QUIT command.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function quit()
    {
        // Although RFC says QUIT can be issued at any time, we won't
        if ($this->state['helo']) {
            $this->send('QUIT');
            $this->expect(
                [self::SMTP_GENERIC_SUCCESS,self::SMTP_QUIT_SUCCESS],
                $this->command_timeouts['quit'],
                true
            );
        }
    }

    /**
     * Sends a NOOP command.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function noop()
    {
        // Bail if NOOPs are not to be sent.
        if (!$this->send_noops) {
            return;
        }

        $this->send('NOOP');

        /**
         * The `SMTP` string is here to fix issues with some bad RFC implementations.
         * Found at least 1 server replying to NOOP without any code.
         */
        $expected_codes = [
            'SMTP',
            self::SMTP_BAD_SEQUENCE,
            self::SMTP_NOT_IMPLEMENTED,
            self::SMTP_GENERIC_SUCCESS,
            self::SMTP_SYNTAX_ERROR,
            self::SMTP_CONNECT_SUCCESS
        ];
        $this->expect($expected_codes, $this->command_timeouts['noop'], true);
    }

    /**
     * Sends a command to the remote host.
     *
     * @param string $cmd The command to send.
     *
     * @return int|bool Number of bytes written to the stream.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     */
    protected function send($cmd)
    {
        // Must be connected
        $this->throwIfNotConnected();

        $this->debug('send>>>: ' . $cmd);
        // Write the cmd to the connection stream
        $result = fwrite($this->socket, $cmd . self::CRLF);

        // Did it work?
        if (false === $result) {
            throw new SendFailedException('Send failed on: ' . $this->host);
        }

        return $result;
    }

    /**
     * Receives a response line from the remote host.
     *
     * @param int $timeout Timeout in seconds.
     *
     * @return string Response line from the remote host.
     *
     * @throws NoConnectionException
     * @throws TimeoutException
     * @throws NoResponseException
     */
    protected function recv($timeout = null)
    {
        // Must be connected
        $this->throwIfNotConnected();

        // Has a custom timeout been specified?
        if (null !== $timeout) {
            stream_set_timeout($this->socket, $timeout);
        }

        // Retrieve response
        $line = fgets($this->socket, 1024);
        $this->debug('<<<recv: ' . $line);

        // Have we timed out?
        $info = stream_get_meta_data($this->socket);
        if (!empty($info['timed_out'])) {
            throw new TimeoutException('Timed out in recv');
        }

        // Did we actually receive anything?
        if (false === $line) {
            throw new NoResponseException('No response in recv');
        }

        return $line;
    }

    /**
     * @param int|int[]|array|string $codes List of one or more expected response codes.
     * @param int|null $timeout The timeout for this individual command, if any.
     * @param bool $empty_response_allowed When true, empty responses are allowed.
     *
     * @return string The last text message received.
     *
     * @throws NoConnectionException
     * @throws SendFailedException
     * @throws TimeoutException
     * @throws UnexpectedResponseException
     */
    protected function expect($codes, $timeout = null, $empty_response_allowed = false)
    {
        if (!is_array($codes)) {
            $codes = (array) $codes;
        }

        $code = null;
        $text = '';

        try {
            $line = $this->recv($timeout);
            $text = $line;
            while (preg_match('/^[0-9]+-/', $line)) {
                $line  = $this->recv($timeout);
                $text .= $line;
            }
            sscanf($line, '%d%s', $code, $text);
            // TODO/FIXME: This is terrible to read/comprehend
            if ($code == self::SMTP_SERVICE_UNAVAILABLE ||
                (false === $empty_response_allowed && (null === $code || !in_array($code, $codes)))) {
                throw new UnexpectedResponseException($line);
            }
        } catch (NoResponseException $e) {
            /**
             * No response in expect() probably means that the remote server
             * forcibly closed the connection so lets clean up on our end as well?
             */
            $this->debug('No response in expect(): ' . $e->getMessage());
            $this->disconnect(false);
        }

        return $text;
    }

    /**
     * Splits the email address string into its respective user and domain parts
     * and returns those as an array.
     *
     * @param string $email Email address.
     *
     * @return array ['user', 'domain']
     */
    protected function splitEmail($email)
    {
        $parts  = explode('@', $email);
        $domain = array_pop($parts);
        $user   = implode('@', $parts);

        return [$user, $domain];
    }

    /**
     * Sets the email addresses that should be validated.
     *
     * @param array|string $emails List of email addresses (or a single one a string).
     */
    public function setEmails($emails)
    {
        if (!is_array($emails)) {
            $emails = (array) $emails;
        }

        $this->domains = [];

        foreach ($emails as $email) {
            list($user, $domain) = $this->splitEmail($email);
            if (!isset($this->domains[$domain])) {
                $this->domains[$domain] = [];
            }
            $this->domains[$domain][] = $user;
        }
    }

    /**
     * Sets the email address to use as the sender/validator.
     *
     * @param string $email
     */
    public function setSender($email)
    {
        $parts             = $this->splitEmail($email);
        $this->from_user   = $parts[0];
        $this->from_domain = $parts[1];
    }

    /**
     * Queries the DNS server for MX entries of a certain domain.
     *
     * @param string $domain The domain for which to retrieve MX records.
     *
     * @return array MX hosts and their weights.
     */
    protected function mxQuery($domain)
    {
        $hosts  = [];
        $weight = [];
        getmxrr($domain, $hosts, $weight);

        return [$hosts, $weight];
    }

    /**
     * Throws if not currently connected.
     *
     * @throws NoConnectionException
     */
    private function throwIfNotConnected()
    {
        if (!$this->connected()) {
            throw new NoConnectionException('No connection');
        }
    }

    /**
     * Debug helper. If it detects a CLI env, it just dumps given `$str` on a
     * new line, otherwise it prints stuff <pre>.
     *
     * @param string $str
     */
    private function debug($str)
    {
        $str = $this->stamp($str);
        $this->log($str);
        if ($this->debug) {
            if ('cli' !== PHP_SAPI) {
                $str = '<br/><pre>' . htmlspecialchars($str) . '</pre>';
            }
            echo "\n" . $str;
        }
    }

    /**
     * Adds a message to the log array
     *
     * @param string $msg
     */
    private function log($msg)
    {
        $this->log[] = $msg;
    }

    /**
     * Prepends the given $msg with the current date and time inside square brackets.
     *
     * @param string $msg
     *
     * @return string
     */
    private function stamp($msg)
    {
        $line = '[' . $this->getLogDate() . '] ' . $msg;

        return $line;
    }

    /**
     * Logging helper which returns (formatted) current date and time
     * (with microseconds) but avoids sprintf/microtime(true) combo.
     * Empty string returned on failure.
     *
     * @see https://github.com/zytzagoo/smtp-validate-email/pull/58
     *
     * @return string
     */
    public function getLogDate()
    {
        $dt = \DateTime::createFromFormat('0.u00 U', microtime());

        $date = '';
        if (false !== $dt) {
            $date = $dt->format('Y-m-d\TH:i:s.uO');
        }

        return $date;
    }

    /**
     * Returns the log array.
     *
     * @return array
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * Truncates the log array.
     */
    public function clearLog()
    {
        $this->log = [];
    }

    /**
     * Compat for old lower_cased method calls.
     *
     * @param string $name
     * @param array  $args
     *
     * @return mixed
     */
    public function __call($name, $args)
    {
        $camelized = self::camelize($name);
        if (\method_exists($this, $camelized)) {
            return \call_user_func_array([$this, $camelized], $args);
        } else {
            trigger_error('Fatal error: Call to undefined method ' . self::class . '::' . $name . '()', E_USER_ERROR);
        }
    }

    /**
     * Set the desired connect timeout.
     *
     * @param int $timeout Connect timeout in seconds.
     */
    public function setConnectTimeout($timeout)
    {
        $this->connect_timeout = (int) $timeout;
    }

    /**
     * Get the current connect timeout.
     *
     * @return int
     */
    public function getConnectTimeout()
    {
        return $this->connect_timeout;
    }

    /**
     * Set connect port.
     *
     * @param int $port
     */
    public function setConnectPort($port)
    {
        $this->connect_port = (int) $port;
    }

    /**
     * Get current connect port.
     *
     * @return int
     */
    public function getConnectPort()
    {
        return $this->connect_port;
    }

    /**
     * Turn on "catch-all" detection.
     */
    public function enableCatchAllTest()
    {
        $this->catchall_test = true;
    }

    /**
     * Turn off "catch-all" detection.
     */
    public function disableCatchAllTest()
    {
        $this->catchall_test = false;
    }

    /**
     * Returns whether "catch-all" test is to be performed or not.
     *
     * @return bool
     */
    public function isCatchAllEnabled()
    {
        return $this->catchall_test;
    }

    /**
     * Set whether "catch-all" results are considered valid or not.
     *
     * @param bool $flag When true, "catch-all" accounts are considered valid
     */
    public function setCatchAllValidity($flag)
    {
        $this->catchall_is_valid = (bool) $flag;
    }

    /**
     * Get current state of "catch-all" validity flag.
     *
     * @return bool
     */
    public function getCatchAllValidity()
    {
        return $this->catchall_is_valid;
    }

    /**
     * Control sending of NOOP commands.
     *
     * @param bool $val
     */
    public function sendNoops($val)
    {
        $this->send_noops = (bool) $val;
    }

    /**
     * @return bool
     */
    public function sendingNoops()
    {
        return $this->send_noops;
    }

    /**
     * Camelizes a string.
     *
     * @param string $id String to camelize.
     *
     * @return string
     */
    private static function camelize($id)
    {
        return strtr(
            ucwords(
                strtr(
                    $id,
                    ['_' => ' ', '.' => '_ ', '\\' => '_ ']
                )
            ),
            [' ' => '']
        );
    }
}
