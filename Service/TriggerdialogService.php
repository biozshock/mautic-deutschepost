<?php

namespace MauticPlugin\MauticTriggerdialogBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticTriggerdialogBundle\Entity\TriggerCampaign;
use MauticPlugin\MauticTriggerdialogBundle\Exception\RequestException;
use MauticPlugin\MauticTriggerdialogBundle\Helper\AudienceHelper;
use Symfony\Component\HttpFoundation\Session\Session;

class TriggerdialogService
{
    public const LOCALE = 'de';

    public const SESSION_KEY = 'triggerdialog';

    public const STATE_DRAFT    = 110;
    public const STATE_ACTIVE   = 120;
    public const STATE_PAUSED   = 125;
    public const STATE_FINISHED = 130;
    public const STATE_DELETED  = 140;

    public const LOOKUP_ALL                    = 'All';
    public const LOOKUP_ESTIMATION_OPTION      = 'EstimationOption';
    public const LOOKUP_INDIVIDUALIZATION      = 'Individualization';
    public const LOOKUP_VARIABLE_DEF_DATA_TYPE = 'VariableDefDataType';
    public const LOOKUP_PRINT_PROCESS          = 'PrintingProcess';
    public const LOOKUP_CAMPAIGN_STATE         = 'CampaignState';
    public const LOOKUP_DELIVERY_PRODUCT       = 'DeliveryProduct';
    public const LOOKUP_SENDING_REASON         = 'SendingReason';
    public const LOOKUP_DELIVERY_CHECK_STATE   = 'DeliveryCheckState';

    /**
     * @var static
     */
    protected static $_instance;

    /**
     * @var Client
     */
    protected $client;

    protected $partnerSystemIdExt = '';

    protected $authenticationSecret = '';

    protected $partnerSystemCustomerIdExt = '';

    protected $customerId = '';

    protected $accessToken = '';

    protected $config = [
        RequestOptions::HTTP_ERRORS => false,
        RequestOptions::HEADERS     => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'mautic-td/2.0',
        ],
    ];

    protected function __construct(int $partnerSystemIdExt, string $partnerSystemCustomerIdExt, string $authenticationSecret)
    {
        $this->client                     = new Client(array_merge($this->config, ['base_uri' => AudienceHelper::getAudience()]));
        $this->partnerSystemIdExt         = $partnerSystemIdExt;
        $this->partnerSystemCustomerIdExt = $partnerSystemCustomerIdExt;
        $this->authenticationSecret       = $authenticationSecret;
        $this->loadAccessToken();
    }

    public static function makeInstance(int $masId, string $masClientId, string $authenticationSecret): self
    {
        if (null === self::$_instance) {
            self::$_instance = new self($masId, $masClientId, $authenticationSecret);
        }

        return self::$_instance;
    }

    /*
     * Campaign Endpoints
     */

    public function createCampaign(TriggerCampaign $triggerCampaign): TriggerCampaign
    {
        $response = $this->client->request(
            'POST',
            '/automation/longtermcampaigns',
            [
                RequestOptions::JSON    => $this->transformTriggerCampaign($triggerCampaign),
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (201 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605027739);
        }

        $content = json_decode($response->getBody()->getContents(), true);
        $triggerCampaign->setTriggerId((int) $content['id']);

        $this->createMailing($triggerCampaign);
        $this->createVariableDefinitions($triggerCampaign);

        return $triggerCampaign;
    }

    public function getCampaign(TriggerCampaign $triggerCampaign): array
    {
        $response = $this->client->request(
            'GET',
            sprintf('/automation/longtermcampaigns/%d', $triggerCampaign->getTriggerId()),
            [
                RequestOptions::QUERY => [
                    'customerId' => $this->customerId,
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605089364);
        }

        return \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
    }

    public function updateCampaign(TriggerCampaign $triggerCampaign): void
    {
        $response = $this->client->request(
            'PUT',
            sprintf('/automation/longtermcampaigns/%d', $triggerCampaign->getTriggerId()),
            [
                RequestOptions::JSON    => $this->transformTriggerCampaign($triggerCampaign, false),
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605089364);
        }
    }

    public function updateCampaignStage(TriggerCampaign $triggerCampaign, int $state = self::STATE_DRAFT): void
    {
        $response = $this->client->request(
            'PUT',
            sprintf('/automation/longtermcampaigns/%d/state', $triggerCampaign->getTriggerId()),
            [
                RequestOptions::JSON => [
                    'customerId' => $this->customerId,
                    'stateId'    => $state,
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605089193);
        }
    }

    public function listCampaigns(int $offset = 0, int $pageNumber = 0, int $pageSize = 10, bool $paged = true, bool $unpaged = false, bool $forceFirstAndLastRels = false): array
    {
        $response = $this->client->request(
            'GET',
            '/automation/longtermcampaigns',
            [
                RequestOptions::QUERY => [
                    'customerId'            => $this->customerId,
                    'offset'                => $offset,
                    'pageNumber'            => $pageNumber,
                    'pageSize'              => $pageSize,
                    'paged'                 => $paged,
                    'unpaged'               => $unpaged,
                    'forceFirstAndLastRels' => $forceFirstAndLastRels,
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605089364);
        }

        return \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
    }

    /*
     * Mailing Endpoints
     */

    public function createMailing(TriggerCampaign &$triggerCampaign): void
    {
        $response = $this->client->request(
            'POST',
            '/automation/mailings',
            [
                RequestOptions::JSON => [
                    'customerId' => $this->customerId,
                    'campaignId' => $triggerCampaign->getTriggerId(),
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605027608);
        }

        $content = json_decode($response->getBody()->getContents(), true);
        $triggerCampaign->setMailingId((int) $content['id']);
    }

    public function getAddressVariables(): array
    {
        $response = $this->client->request(
            'GET',
            '/automation/mailings/addressvariables',
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605090809);
        }

        return \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
    }

    public function listMailings(TriggerCampaign $triggerCampaign): array
    {
        $response = $this->client->request(
            'GET',
            '/automation/mailings',
            [
                RequestOptions::QUERY => [
                    'customerId' => $this->customerId,
                    'campaignId' => $triggerCampaign->getTriggerId(),
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605090762);
        }

        return \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
    }

    public function deleteMailing(TriggerCampaign $triggerCampaign): void
    {
        $response = $this->client->request(
            'DELETE',
            '/automation/mailings',
            [
                RequestOptions::QUERY => [
                    'customerId' => $this->customerId,
                    'campaignId' => $triggerCampaign->getTriggerId(),
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605090777);
        }
    }

    public function createVariableDefinitions(TriggerCampaign $triggerCampaign): void
    {
        $response = $this->client->request(
            'POST',
            sprintf('/automation/mailings/%d/variabledefinitions', $triggerCampaign->getMailingId()),
            [
                RequestOptions::JSON => [
                    'customerId'                      => $this->customerId,
                    'createVariableDefRequestRepList' => $this->transformVariableDefinitions($triggerCampaign),
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (201 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605027701);
        }
    }

    public function updateCampaignVariable(TriggerCampaign $triggerCampaign): void
    {
        $response = $this->client->request(
            'PUT',
            sprintf('/automation/mailings/%d/variabledefinitions', $triggerCampaign->getMailingId()),
            [
                RequestOptions::JSON => [
                    'customerId'                      => $this->customerId,
                    'updateVariableDefRequestRepList' => $this->transformVariableDefinitions($triggerCampaign),
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1569423229);
        }
    }

    /*
     * Miscellaneous Endpoints
     */

    public function dataLookup(string $type = self::LOOKUP_ALL): array
    {
        $response = $this->client->request(
            'GET',
            '/automation/campaignlookups',
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605090809);
        }

        $content = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);

        return self::LOOKUP_ALL === $type ? $content : $this->flipLookup($content[$type]);
    }

    public function createRecipient(TriggerCampaign $triggerCampaign, Lead $lead): void
    {
        $response = $this->client->request(
            'POST',
            '/automation/recipients/',
            [
                RequestOptions::JSON => [
                    'campaignId' => $triggerCampaign->getTriggerId(),
                    'customerId' => $this->customerId,
                    'recipients' => [
                        $this->getRecipientAddress($triggerCampaign, $lead),
                    ],
                ],
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]
        );

        if (202 !== $response->getStatusCode()) {
            throw new RequestException($response, 1569423375);
        }
    }

    /*
     * Access Token (Bearer / JWT)
     */

    protected function loadAccessToken(): void
    {
        $session = new Session();

        if (!$this->loadAccessTokenFromSession($session)) {
            $this->accessToken = $this->getAccessToken();

            // Since we have no secret and Deutsche Post will not provide one, we have to decode the token ourself.
            [$header, $payload, $signature] = explode('.', $this->accessToken);
            $data                           = \GuzzleHttp\json_decode(base64_decode($payload), true);
            $this->customerId               = (string) ($data['customerIds'][0] ?? 0);

            if ('0' === $this->customerId) {
                throw new \Exception('No customer ID given in response.', 1605084861);
            }

            $session->set(self::SESSION_KEY, [
                'accessToken' => $this->accessToken,
                'customerId'  => $this->customerId,
                'exp'         => $data['exp'],
            ]);
        }
    }

    protected function loadAccessTokenFromSession(Session $session): bool
    {
        $settings = $session->get(self::SESSION_KEY);

        if (!empty($settings)) {
            $validUntil = time() - 30;

            if (($settings['exp'] ?? 0) > $validUntil) {
                $this->accessToken = $settings['accessToken'];
                $this->customerId  = $settings['customerId'];

                return true;
            }
        }

        // Get new token as the existing one is expired or will expire shortly
        return false;
    }

    protected function getAccessToken(): string
    {
        $response = $this->client->request(
            'POST',
            '/user/authentication/partnersystem/credentialsbased',
            [
                RequestOptions::JSON => [
                    'partnerSystemIdExt'         => $this->partnerSystemIdExt,
                    'partnerSystemCustomerIdExt' => $this->partnerSystemCustomerIdExt,
                    'authenticationSecret'       => $this->authenticationSecret,
                    'locale'                     => self::LOCALE,
                ],
            ]
        );

        if (200 !== $response->getStatusCode()) {
            throw new RequestException($response, 1605082783);
        }

        $contents    = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
        $accessToken = $contents['jwtToken'] ?? '';

        if (empty($accessToken)) {
            // TODO: Handle this case.
        }

        return $accessToken;
    }

    /*
     * Helper
     */

    protected function flipLookup(array $content = []): array
    {
        $data = [];

        foreach ($content as $item) {
            $data[$item['id']] = $item['label'];
        }

        return $data;
    }

    protected function transformTriggerCampaign(TriggerCampaign $triggerCampaign, bool $includeId = true): array
    {
        $data = [
            'campaignName' => $triggerCampaign->getName(),
            'customerId'   => $this->customerId,
            'startDate'    => $triggerCampaign->getStartDate()->format('Y-m-d'),
        ];

        if (true === $includeId) {
            $data['campaignIdExt'] = $triggerCampaign->getId();
        }

        if (($endDate = $triggerCampaign->getEndDate()) instanceof \DateTimeInterface) {
            $data['endDate'] = $endDate->format('Y-m-d');
        }

        return $data;
    }

    protected function transformVariableDefinitions(TriggerCampaign $triggerCampaign): array
    {
        $variables          = [];
        $processedVariables = [];
        $dataTypes          = array_map([$this, 'transformVariable'], $this->dataLookup(self::LOOKUP_VARIABLE_DEF_DATA_TYPE));
        $dataTypes          = array_flip($dataTypes);

        foreach ($triggerCampaign->getVariables() as $sortOrder => $variable) {
            $label = $variable['field'];

            if (isset($processedVariables[$label])) {
                // Continue since property already exists
                continue;
            }

            $processedVariables[$label] = true;
            $variables[]                = [
                'label'      => $label,
                'sortOrder'  => $sortOrder,
                'dataTypeId' => $dataTypes[$variable['variable']],
            ];
        }

        return $variables;
    }

    protected function transformVariable(string $definition): string
    {
        return preg_replace('/\s+/', '', mb_strtolower($definition));
    }

    protected function getRecipientAddress(TriggerCampaign $triggerCampaign, Lead $lead)
    {
        $recipientData = [];

        foreach ($triggerCampaign->getVariables() as $variable) {
            $field           = $variable['field'];
            $recipientData[] = [
                'label' => $field,
                'value' => $lead->getFieldValue($field),
            ];
        }

        return [
            'recipientIdExt' => $lead->getId(),
            'recipientData'  => $recipientData,
        ];
    }
}
