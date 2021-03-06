<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\OpenBadgeBundle\Controller\API;

use Claroline\AppBundle\Controller\AbstractCrudController;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Entity\Workspace\Workspace;
use Claroline\CoreBundle\Library\Normalizer\DateNormalizer;
use Claroline\CoreBundle\Library\Normalizer\TextNormalizer;
use Claroline\CoreBundle\Security\PermissionCheckerTrait;
use Claroline\OpenBadgeBundle\Entity\Assertion;
use Claroline\OpenBadgeBundle\Entity\BadgeClass;
use Claroline\OpenBadgeBundle\Manager\OpenBadgeManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/badge-class")
 */
class BadgeClassController extends AbstractCrudController
{
    use PermissionCheckerTrait;

    /** @var AuthorizationCheckerInterface */
    private $authorization;
    /** @var TokenStorageInterface */
    private $tokenStorage;
    /** @var TranslatorInterface */
    private $translator;
    /** @var OpenBadgeManager */
    private $manager;

    public function __construct(
        AuthorizationCheckerInterface $authorization,
        TokenStorageInterface $tokenStorage,
        TranslatorInterface $translator,
        OpenBadgeManager $manager
    ) {
        $this->authorization = $authorization;
        $this->tokenStorage = $tokenStorage;
        $this->translator = $translator;
        $this->manager = $manager;
    }

    public function getName()
    {
        return 'badge-class';
    }

    public function getClass()
    {
        return BadgeClass::class;
    }

    /**
     * @Route("/enable", name="apiv2_badge-class_enable", methods={"PUT"})
     */
    public function enableAction(Request $request): JsonResponse
    {
        $badges = $this->decodeIdsString($request, BadgeClass::class);

        foreach ($badges as $badge) {
            try {
                $this->crud->replace($badge, 'enabled', true);
            } catch (\Exception $e) {
                // do not break the whole process if user has no right on one of the badges
            }
        }

        return new JsonResponse(
            array_map(function (BadgeClass $badge) {
                return $this->serializer->serialize($badge);
            }, $badges)
        );
    }

    /**
     * @Route("/disable", name="apiv2_badge-class_disable", methods={"PUT"})
     */
    public function disableAction(Request $request): JsonResponse
    {
        $badges = $this->decodeIdsString($request, BadgeClass::class);

        foreach ($badges as $badge) {
            try {
                $this->crud->replace($badge, 'enabled', false);
            } catch (\Exception $e) {
                // do not break the whole process if user has no right on one of the badges
            }
        }

        return new JsonResponse(
            array_map(function (BadgeClass $badge) {
                return $this->serializer->serialize($badge);
            }, $badges)
        );
    }

    /**
     * @Route("/workspace/{workspace}", name="apiv2_badge-class_workspace_badge_list", methods={"GET"})
     * @EXT\ParamConverter("workspace", class="ClarolineCoreBundle:Workspace\Workspace", options={"mapping": {"workspace": "uuid"}})
     */
    public function getWorkspaceBadgesAction(Request $request, Workspace $workspace): JsonResponse
    {
        return new JsonResponse(
            $this->finder->search(BadgeClass::class, array_merge(
                $request->query->all(),
                ['hiddenFilters' => ['workspace' => $workspace->getUuid()]]
            ))
        );
    }

    /**
     * @Route("/{badge}/users", name="apiv2_badge-class_assertion", methods={"GET"})
     * @EXT\ParamConverter("badge", class="ClarolineOpenBadgeBundle:BadgeClass", options={"mapping": {"badge": "uuid"}})
     */
    public function listUsersAction(Request $request, BadgeClass $badge)
    {
        if ($badge->getHideRecipients()) {
            $this->checkPermission('GRANT', $badge, [], true);
        } else {
            $this->checkPermission('OPEN', $badge, [], true);
        }

        return new JsonResponse(
            $this->finder->search(Assertion::class, array_merge(
                $request->query->all(),
                ['hiddenFilters' => ['badge' => $badge->getUuid(), 'revoked' => false]]
            ))
        );
    }

    /**
     * @Route("/{badge}/users/add", name="apiv2_badge-class_add_users", methods={"PATCH"})
     * @EXT\ParamConverter("badge", class="ClarolineOpenBadgeBundle:BadgeClass", options={"mapping": {"badge": "uuid"}})
     */
    public function addUsersAction(BadgeClass $badge, Request $request): JsonResponse
    {
        $this->checkPermission('GRANT', $badge, [], true);

        $users = $this->decodeIdsString($request, User::class);

        foreach ($users as $user) {
            $this->manager->addAssertion($badge, $user);
        }

        return new JsonResponse(
            $this->serializer->serialize($badge)
        );
    }

    /**
     * @Route("/{badge}/users/remove", name="apiv2_badge-class_remove_users", methods={"DELETE"})
     * @EXT\ParamConverter("badge", class="ClarolineOpenBadgeBundle:BadgeClass", options={"mapping": {"badge": "uuid"}})
     */
    public function removeUsersAction(BadgeClass $badge, Request $request): JsonResponse
    {
        $this->checkPermission('GRANT', $badge, [], true);

        $assertions = $this->decodeIdsString($request, Assertion::class);

        foreach ($assertions as $assertion) {
            $this->manager->revokeAssertion($assertion);
        }

        return new JsonResponse(
            $this->serializer->serialize($badge)
        );
    }

    /**
     * @Route("/{badge}/users/export", name="apiv2_badge-class_export_users", methods={"GET"})
     * @EXT\ParamConverter("badge", class="ClarolineOpenBadgeBundle:BadgeClass", options={"mapping": {"badge": "uuid"}})
     */
    public function exportUsersAction(BadgeClass $badge): StreamedResponse
    {
        $this->checkPermission('GRANT', $badge, [], true);

        /** @var Assertion[] $assertions */
        $assertions = $this->om->getRepository(Assertion::class)->findBy([
            'badge' => $badge,
        ]);

        $fileName = "assertions-{$badge->getName()}";
        $fileName = TextNormalizer::toKey($fileName);

        return new StreamedResponse(function () use ($assertions) {
            // Prepare CSV file
            $handle = fopen('php://output', 'w+');

            // Create header
            fputcsv($handle, [
                $this->translator->trans('last_name', [], 'platform'),
                $this->translator->trans('first_name', [], 'platform'),
                $this->translator->trans('email', [], 'platform'),
                $this->translator->trans('date', [], 'platform'),
                $this->translator->trans('revoked', [], 'badge'),
            ], ';', '"');

            foreach ($assertions as $assertion) {
                // put Workspace evaluation
                fputcsv($handle, [
                    $assertion->getRecipient()->getLastName(),
                    $assertion->getRecipient()->getFirstName(),
                    $assertion->getRecipient()->getEmail(),
                    DateNormalizer::normalize($assertion->getIssuedOn()),
                    $assertion->getRevoked(),
                ], ';', '"');
            }

            fclose($handle);

            return $handle;
        }, 200, [
            'Content-Type' => 'application/force-download',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'.csv"',
        ]);
    }
}
