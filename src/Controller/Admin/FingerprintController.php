<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Controller\Admin;

use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FingerprintController extends AbstractController
{
    public function __construct(private readonly FingerprintReader $reader)
    {
    }

    #[Route('/fingerprint/{id}', name: 'error_digest_fingerprint_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $this->guard();

        $fingerprint = $this->reader->find($id);
        if ($fingerprint === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@ErrorDigest/fingerprint/show.html.twig', [
            'fp' => $fingerprint,
            'occurrences' => $this->reader->occurrences($id),
        ]);
    }

    #[Route('/fingerprint/{id}/status', name: 'error_digest_fingerprint_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(int $id, Request $request): Response
    {
        $this->guard();
        $this->requireCsrf($request, 'error_digest_status_' . $id);

        $status = (string) $request->request->get('status');
        $this->reader->updateStatus($id, $status);
        $this->addFlash('success', sprintf('Fingerprint #%d marked as %s.', $id, $status));

        return $this->redirectToRoute('error_digest_fingerprint_show', ['id' => $id]);
    }

    #[Route('/fingerprint/{id}/assign', name: 'error_digest_fingerprint_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(int $id, Request $request): Response
    {
        $this->guard();
        $this->requireCsrf($request, 'error_digest_assign_' . $id);

        $assignee = trim((string) $request->request->get('assignee', ''));
        $this->reader->updateAssignee($id, $assignee === '' ? null : $assignee);
        $this->addFlash('success', sprintf('Fingerprint #%d assignee updated.', $id));

        return $this->redirectToRoute('error_digest_fingerprint_show', ['id' => $id]);
    }

    private function guard(): void
    {
        $role = (string) $this->getParameter('error_digest.ui.role');
        $this->denyAccessUnlessGranted($role);
    }

    private function requireCsrf(Request $request, string $id): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
