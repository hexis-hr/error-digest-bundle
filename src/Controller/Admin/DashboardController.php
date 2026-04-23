<?php

declare(strict_types=1);

namespace Hexis\ErrorDigestBundle\Controller\Admin;

use Hexis\ErrorDigestBundle\Storage\FingerprintReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly FingerprintReader $reader)
    {
    }

    #[Route('', name: 'error_digest_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $role = (string) $this->getParameter('error_digest.ui.role');
        $this->denyAccessUnlessGranted($role);

        $filters = [
            'status' => $request->query->get('status') ?: 'open',
            'level' => $request->query->get('level'),
            'channel' => $request->query->get('channel'),
            'search' => $request->query->get('q'),
        ];

        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $result = $this->reader->list($filters, self::PAGE_SIZE, $offset);

        return $this->render('@ErrorDigest/dashboard/index.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'channels' => $this->reader->distinctChannels(),
        ]);
    }
}
