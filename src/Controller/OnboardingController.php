<?php

namespace App\Controller;

use App\Entity\PatientProfile;
use App\Entity\User;
use App\Form\ProfileFormType;
use App\Repository\PatientProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OnboardingController extends AbstractController
{
    #[Route('/onboarding', name: 'patient_onboarding', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, PatientProfileRepository $patientProfileRepository, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $patientProfileRepository->findOneByUser($user)) {
            return $this->redirectToRoute('patient_profile');
        }

        $profile = new PatientProfile($user, 0, 0);
        $form = $this->createForm(ProfileFormType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($profile);
            $entityManager->flush();

            return $this->redirectToRoute('patient_profile');
        }

        return $this->render('onboarding/index.html.twig', [
            'form' => $form,
        ]);
    }
}
