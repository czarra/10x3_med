<?php

namespace App\Controller;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Form\DiaryEntryFormType;
use App\Repository\PatientProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DiaryController extends AbstractController
{
    #[Route('/dziennik/nowy', name: 'diary_entry_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, PatientProfileRepository $patientProfileRepository, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $patientProfileRepository->findOneByUser($user);
        if (null === $profile) {
            throw $this->createNotFoundException('Patient profile not found.');
        }

        $entry = new DiaryEntry($user, $profile->getInsulinWwRatio(), $profile->getBaseDose());
        $form = $this->createForm(DiaryEntryFormType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();
            $this->addFlash('success', 'Wpis został zapisany.');

            return $this->redirectToRoute('diary_entry_new');
        }

        return $this->render('diary/new.html.twig', [
            'form' => $form,
        ]);
    }
}
