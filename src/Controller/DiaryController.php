<?php

namespace App\Controller;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Form\DiaryEntryFormType;
use App\Repository\DiaryEntryRepository;
use App\Repository\PatientProfileRepository;
use App\Security\DiaryEntryVoter;
use App\Service\Chart\GlucoseHistoryChartService;
use App\Service\Export\DiaryExportService;
use App\Service\History\DiaryHistoryService;
use App\Service\Warning\HypoglycemiaWarningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DiaryController extends AbstractController
{
    #[Route('/dziennik/nowy', name: 'diary_entry_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, PatientProfileRepository $patientProfileRepository, EntityManagerInterface $entityManager, HypoglycemiaWarningService $hypoglycemiaWarningService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $patientProfileRepository->findOneByUser($user);
        if (null === $profile) {
            throw $this->createNotFoundException('Patient profile not found.');
        }

        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: 0,
            measuredAt: new \DateTimeImmutable(),
            insulinWwRatioSnapshot: $profile->getInsulinWwRatio(),
            baseDoseSnapshot: $profile->getBaseDose(),
        );
        $form = $this->createForm(DiaryEntryFormType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();
            $this->addFlash('success', 'Wpis został zapisany.');

            $warning = $hypoglycemiaWarningService->evaluate($entry);
            if ($warning->available) {
                $this->addFlash('warning', $warning->message);
            }

            return $this->redirectToRoute('diary_entry_new');
        }

        return $this->render('diary/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/dziennik/{id}/edytuj', name: 'diary_entry_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(int $id, Request $request, DiaryEntryRepository $diaryEntryRepository, EntityManagerInterface $entityManager): Response
    {
        $entry = $diaryEntryRepository->find($id);
        if (null === $entry) {
            throw $this->createNotFoundException('Diary entry not found.');
        }

        if (!$this->isGranted(DiaryEntryVoter::EDIT, $entry)) {
            throw $this->createNotFoundException('Diary entry not found.');
        }

        $form = $this->createForm(DiaryEntryFormType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Wpis został zaktualizowany.');

            return $this->redirectToRoute('diary_entry_history');
        }

        return $this->render('diary/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/dziennik/{id}/usun', name: 'diary_entry_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id, Request $request, DiaryEntryRepository $diaryEntryRepository, EntityManagerInterface $entityManager): Response
    {
        $entry = $diaryEntryRepository->find($id);
        if (null === $entry) {
            throw $this->createNotFoundException('Diary entry not found.');
        }

        if (!$this->isGranted(DiaryEntryVoter::DELETE, $entry)) {
            throw $this->createNotFoundException('Diary entry not found.');
        }

        if (!$this->isCsrfTokenValid('delete_diary_entry', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($entry);
        $entityManager->flush();
        $this->addFlash('success', 'Wpis został usunięty.');

        return $this->redirectToRoute('diary_entry_history');
    }

    #[Route('/dziennik/historia', name: 'diary_entry_history', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function history(Request $request, DiaryHistoryService $diaryHistoryService, GlucoseHistoryChartService $glucoseHistoryChartService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = max(1, $request->query->getInt('page', 1));
        $historyPage = $diaryHistoryService->buildPage($user, $page);
        $chart = $glucoseHistoryChartService->buildFor($user, new \DateTimeImmutable());

        return $this->render('diary/history.html.twig', [
            'historyPage' => $historyPage,
            'chart' => $chart,
        ]);
    }

    #[Route('/dziennik/eksport', name: 'diary_entry_export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function export(Request $request, DiaryHistoryService $diaryHistoryService, DiaryExportService $diaryExportService): StreamedResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = max(1, $request->query->getInt('page', 1));
        $historyPage = $diaryHistoryService->buildPage($user, $page);

        $response = new StreamedResponse(function () use ($historyPage, $diaryExportService): void {
            $handle = fopen('php://output', 'w');
            $diaryExportService->writeCsv($historyPage, $handle);
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            sprintf('dziennik-eksport-strona-%d-%s.csv', $historyPage->currentPage, (new \DateTimeImmutable())->format('Y-m-d')),
        ));

        return $response;
    }
}
