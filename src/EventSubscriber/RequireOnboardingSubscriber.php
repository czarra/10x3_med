<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\PatientProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RequireOnboardingSubscriber implements EventSubscriberInterface
{
    private const EXCLUDED_ROUTES = ['patient_onboarding', 'app_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly PatientProfileRepository $patientProfileRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (null === $route || !is_string($route) || str_starts_with($route, '_')) {
            return;
        }

        if (in_array($route, self::EXCLUDED_ROUTES, true)) {
            return;
        }

        if (null !== $this->patientProfileRepository->findOneByUser($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('patient_onboarding')));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
