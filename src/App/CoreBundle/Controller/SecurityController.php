<?php

namespace App\CoreBundle\Controller;

use App\Model\User;
use App\Model\BetaSignup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use DateTime;
use RuntimeException;

class SecurityController extends Controller
{
    public function primusAuthAction(Request $request)
    {
        try {
            # @todo @channel_auth move channel auth to an authenticator service
            $channel = $request->request->get('channel');
            $token = uniqid(mt_rand(), true);            

            if (strlen($channel) > 0) {
                $repo = $this->getDoctrine()->getRepository('Model:User');
                $authUser = $repo->findByChannel($channel);

                if ($authUser !== $this->getUser()) {
                    return new JsonResponse(null, 403);
                }
            } else {
                if (!$this->getUser()) {
                    throw new \RuntimeException();
                }

                $channel = $this->getUser()->getChannel();
            }

            $this->get('app_core.redis')->sadd('channel:auth:' . $channel, $token);

            return new JsonResponse(json_encode([
                'channel' => $this->getUser()->getChannel(),
                'token' => $token,
            ]));            
        } catch (\Exception $e) {
            return new JsonResponse(json_encode(false), 500);
        }
    }
}