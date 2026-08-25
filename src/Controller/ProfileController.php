<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileFormType;
use App\Repository\PatientProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfileController extends AbstractController
{
    #[Route('/profil', name: 'patient_profile', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, PatientProfileRepository $patientProfileRepository, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $patientProfileRepository->findOneByUser($user);
        if (null === $profile) {
            throw $this->createNotFoundException('Patient profile not found.');
        }

        $form = $this->createForm(ProfileFormType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Parametry zostały zaktualizowane.');

            return $this->redirectToRoute('patient_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
