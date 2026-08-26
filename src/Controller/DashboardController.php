<?php

namespace App\Controller;

use App\Entity\BaseDoseAdjustmentHistory;
use App\Entity\RatioAdjustmentHistory;
use App\Entity\User;
use App\Repository\PatientProfileRepository;
use App\Service\Suggestion\BaseDoseSuggestionService;
use App\Service\Suggestion\InsulinWwRatioSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    #[Route('/pulpit', name: 'patient_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        PatientProfileRepository $patientProfileRepository,
        InsulinWwRatioSuggestionService $ratioSuggestionService,
        BaseDoseSuggestionService $baseDoseSuggestionService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $patientProfileRepository->findOneByUser($user);
        if (null === $profile) {
            throw $this->createNotFoundException('Patient profile not found.');
        }

        return $this->render('dashboard/index.html.twig', [
            'ratioSuggestion' => $ratioSuggestionService->suggestFor($user, $profile),
            'baseDoseSuggestion' => $baseDoseSuggestionService->suggestFor($user, $profile),
        ]);
    }

    #[Route('/pulpit/przelicznik/akceptuj', name: 'patient_dashboard_accept_ratio', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptRatio(
        Request $request,
        PatientProfileRepository $patientProfileRepository,
        InsulinWwRatioSuggestionService $ratioSuggestionService,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $patientProfileRepository->findOneByUser($user);
        if (null === $profile) {
            throw $this->createNotFoundException('Patient profile not found.');
        }

        if (!$this->isCsrfTokenValid('accept_ratio_suggestion', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $suggestion = $ratioSuggestionService->suggestFor($user, $profile);
        if ($suggestion->available) {
            $oldRatio = $profile->getInsulinWwRatio();
            $profile->setInsulinWwRatio($suggestion->suggestedRatio);
            $entityManager->persist(new RatioAdjustmentHistory(
                user: $user,
                oldRatio: $oldRatio,
                newRatio: $suggestion->suggestedRatio,
                acceptedAt: new \DateTimeImmutable(),
            ));
            $entityManager->flush();
            $this->addFlash('success', 'Przelicznik insulina/WW został zaktualizowany.');
        }

        return $this->redirectToRoute('patient_dashboard');
    }

    #[Route('/pulpit/dawka-bazowa/akceptuj', name: 'patient_dashboard_accept_base_dose', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptBaseDose(
        Request $request,
        PatientProfileRepository $patientProfileRepository,
        BaseDoseSuggestionService $baseDoseSuggestionService,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $patientProfileRepository->findOneByUser($user);
        if (null === $profile) {
            throw $this->createNotFoundException('Patient profile not found.');
        }

        if (!$this->isCsrfTokenValid('accept_base_dose_suggestion', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $suggestion = $baseDoseSuggestionService->suggestFor($user, $profile);
        if ($suggestion->available) {
            $oldBaseDose = $profile->getBaseDose();
            $profile->setBaseDose($suggestion->suggestedBaseDose);
            $entityManager->persist(new BaseDoseAdjustmentHistory(
                user: $user,
                oldBaseDose: $oldBaseDose,
                newBaseDose: $suggestion->suggestedBaseDose,
                acceptedAt: new \DateTimeImmutable(),
            ));
            $entityManager->flush();
            $this->addFlash('success', 'Dawka bazowa została zaktualizowana.');
        }

        return $this->redirectToRoute('patient_dashboard');
    }
}
