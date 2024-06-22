<?php

namespace Botman\Drivers\Whatsapp;

use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Interfaces\UserInterface;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Users\User;
use BotMan\Drivers\Whatsapp\Exceptions\WhatsappConnectionException;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use BotMan\Drivers\Whatsapp\Extensions\ButtonTemplate as BT;

class WhatsappDriver extends HttpDriver
{
    const DRIVER_NAME = 'Whatsapp';

    protected $endpoint = 'messages';

    /** @var array */
    protected $templates = [
        BT::class,
        // GenericTemplate::class,
        // ListTemplate::class,
        // ReceiptTemplate::class,
        // MediaTemplate::class,
        // OpenGraphTemplate::class,
    ];

    private $supportedAttachments = [
        Video::class,
        Audio::class,
        Image::class,
        File::class,
    ];

    /**
     * @var array
     */
    public $messages = [];

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {
        $parameters = (array) json_decode($request->getContent(), true);
        $this->payload = new ParameterBag($parameters['entry'][0]['changes'][0]['value'] ?? []);

        $this->event = Collection::make((array) $this->payload->get('messages') ? (array) $this->payload->get('messages')[0] : $this->payload);
        $this->content = $request->getContent();
        $this->config = Collection::make($this->config->get('whatsapp', []));
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return $this->payload->get('messaging_product') === 'whatsapp';
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            if ($this->event->get('type') == 'text') {
                $this->messages = [
                    new IncomingMessage(
                        $this->event->get('text')['body'],
                        $this->event->get('from'),
                        $this->event->get('from'),
                        $this->payload
                    )
                ];
            } elseif ($this->event->get('type') == 'image') {
                $this->messages = [
                    new IncomingMessage(
                        isset($this->event->get('image')['caption']) ? $this->event->get('image')['caption'] : '',
                        $this->event->get('from'),
                        $this->event->get('from'),
                        $this->payload
                    )
                ];
            } elseif ($this->event->get('type') == 'document') {
                $this->messages = [
                    new IncomingMessage(
                        isset($this->event->get('document')['caption']) ? $this->event->get('document')['caption'] : '',
                        $this->event->get('from'),
                        $this->event->get('from'),
                        $this->payload
                    )
                ];
            } elseif ($this->event->get('type') == 'location') {
                $this->messages = [
                    new IncomingMessage(
                        $this->event->get('location')['name'],
                        $this->event->get('from'),
                        $this->event->get('from'),
                        $this->payload
                    )
                ];
            } elseif ($this->event->get('type') == 'button' || $this->event->get('type') == 'interactive') {

                $interactive = $this->event->get('interactive');

                if(isset($interactive['button_reply'])) {
                    $message = $interactive['button_reply']['title'];

                } elseif (isset($interactive['list_reply'])) {
                    $message = $interactive['list_reply']['title'];

                } else {
                    $message = $this->event->get('button')['text'] ?? '';

                }

                $this->messages = [
                    new IncomingMessage(
                        $message,
                        $this->event->get('from'),
                        $this->event->get('from'),
                        $this->payload
                    )
                ];
            }
        }

        return $this->messages;
    }

    /**
     * Retrieve User information.
     * @param IncomingMessage $matchingMessage
     * @return UserInterface
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $contact = Collection::make($matchingMessage->getPayload()->get('contacts')[0]);
        return new User(
            $contact->get('wa_id'),
            $contact->get('profile')['name'],
            null,
            $contact->get('wa_id'),
            $contact
        );
    }

    /**
     * @param IncomingMessage $message
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        $answer = Answer::create($message->getText())->setMessage($message);

        $payload = $message->getPayload();

        if ($payload && $payload->get('messages')[0]['type'] === 'interactive') {

            $interactiveElement = $payload->get('messages')[0]['interactive'];
            if ($interactiveElement['type'] === 'button_reply') {
                $answer->setValue($interactiveElement['button_reply']['id']);
                $answer->setInteractiveReply(true);
            } elseif ($interactiveElement['type'] === 'list_reply') {
                $answer->setValue($interactiveElement['list_reply']['id']);
                $answer->setInteractiveReply(true);
            }

        }

        return $answer;
    }

    /**
     * @param string|Question $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return $this
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $recipient = $matchingMessage->getRecipient() === '' ? $matchingMessage->getSender() : $matchingMessage->getRecipient();

        $parameters = array_merge_recursive([
            //'recipient_type' => 'individual',
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
        ], $additionalParameters);

        if ($message instanceof Question) {
            $parameters['text'] = [
                'body' => $message->getText()
            ];
            $parameters['type'] = 'text';

            $buttons = $this->messageActionsToButtons($message->getActions());

            if (count($buttons)) {
                if (count($buttons) > 3) throw new \Exception('WhatsappDriver does not support more than three buttons');

                $parameters['type'] = 'interactive';
                $parameters['interactive'] = [
                    'type'   => 'button',
                    'body'   => [
                        'text' => $message->getText()
                    ],
                    'action' => [
                        'buttons' => $buttons
                    ]
                ];
            }

            $action = $this->messageActionsToInteractiveList($message->getActions());

            if (!empty($action)) {
                $parameters['type'] = 'interactive';
                $parameters['interactive'] = [
                    'type'   => 'list',
                    'body'   => [
                        'text' => $message->getText()
                    ],
                    'action' => $action
                ];
            }

        } elseif (is_object($message) && in_array(get_class($message), $this->templates)) {
            if (get_class($message) === BT::class) {
                $parameters['type'] = 'interactive';
                $parameters['interactive'] = [
                    'type' => 'button',
                    'body' => [
                        'text' => $message->text
                    ],
                    'action' => [
                        'buttons' => $message->buttons,
                    ]
                ];
            } else {
                $parameters['text'] = [
                    'body' => $message->getText(),
                ];
                $parameters['type'] = 'text';
            }
        } elseif (get_class($message) === OutgoingMessage::class) {
            $attachment = $message->getAttachment();

            if (!is_null($attachment) && get_class($attachment) === Image::class) {
                $parameters['type'] = 'image';
                $parameters['image'] = [
                    'link' => $attachment->getUrl()
                ];
            } else {
                $parameters['text'] = [
                    'body' => $message->getText(),
                ];
                $parameters['type'] = 'text';
            }
        } else {
            $parameters['text'] = [
                'body' => $message->getText(),
            ];
            $parameters['type'] = 'text';
        }
        return $parameters;
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {

        if ($this->config->get('throw_http_exceptions')) {
            return $this->postWithExceptionHandling($this->buildApiUrl($this->endpoint), [], $payload, $this->buildAuthHeader(), true);
        }

        return $this->http->post($this->buildApiUrl($this->endpoint), [], $payload, $this->buildAuthHeader(), true);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        // TODO: Check token existence from DB?
        return !empty($this->config->get('url'));
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage) : Response
    {
        $parameters = array_replace_recursive([
            'to' => $matchingMessage->getRecipient(),
        ], $parameters);

        if ($this->config->get('throw_http_exceptions')) {
            return $this->postWithExceptionHandling($this->buildApiUrl($endpoint), [], $parameters, $this->buildAuthHeader());
        }

        return $this->http->post($this->buildApiUrl($endpoint), [], $parameters, $this->buildAuthHeader());
    }

    protected function buildApiUrl($endpoint)
    {
        return $this->config->get('url') . '/' . $endpoint;
    }

    protected function buildAuthHeader()
    {
        /*
        * TODO: Token should from DB & Re-Fetch before expired with Artisan command scheduler
        */
        // $token = 'YOUR-BEARER-TOKEN-HERE';
        $token = $this->config->get('token');
        return [
            "Authorization: Bearer " . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
    }

    /**
     * @param $url
     * @param array $urlParameters
     * @param array $postParameters
     * @param array $headers
     * @param bool $asJSON
     * @param int $retryCount
     * @return Response
     * @throws WhatsappConnectionException
     */
    private function postWithExceptionHandling(
        $url,
        array $urlParameters = [],
        array $postParameters = [],
        array $headers = [],
        bool $asJSON = false,
        int $retryCount = 0
    ): Response {
        $response = $this->http->post($url, $urlParameters, $postParameters, $headers, $asJSON);

        if ($response->isSuccessful()) {
            return $response;
        }

        $responseData = json_decode($response->getContent(), true);

        $responseData['errors']['code'] = $responseData['errors']['code'] ?? 'No description from Vendor';
        $responseData['errors']['title'] = $responseData['errors']['title'] ?? 'No error code from Vendor';

        $message = "Status Code: {$response->getStatusCode()}\n".
            "Description: ".print_r($responseData['errors']['title'], true)."\n".
            "Error Code: ".print_r($responseData['errors']['code'], true)."\n".
            "URL: $url\n".
            "URL Parameters: ".print_r($urlParameters, true)."\n".
            "Post Parameters: ".print_r($postParameters, true)."\n".
            "Headers: ". print_r($headers, true)."\n";

        throw new WhatsappConnectionException($message);
    }

    private function messageActionsToButtons(array $actions): array
    {
        $buttons = [];

        foreach ($actions as $action) {
            if ($action['type'] !== 'button') continue;
            $buttons[] = $this->convertActionToWhatsAppButton($action);
        }

        return $buttons;
    }

    private function convertActionToWhatsAppButton(array $buttonAction): array
    {
        return [
            'type'  => 'reply',
            'reply' => [
                'id'    => $buttonAction['value'],
                'title' => $buttonAction['text']
            ]
        ];
    }

    /**
     * @param array $actions
     * @return array
     */
    private function messageActionsToInteractiveList(array $actions)
    {
        if (($actions[0]['type'] ?? null) !== 'select') return [];

        $action = $actions[0];
        $actionTitle = $action['text'];

        $rows = [];

        foreach ($action['options'] as $option) {
            $rows[] = [
                'id'    => $option['value'],
                'title' => $option['text']
            ];
        }

        return [
            'sections' => [
                [
                    'title' => $actionTitle,
                    'rows'  => $rows,
                ]
            ],
            'button'   => $actionTitle
        ];
    }
}
