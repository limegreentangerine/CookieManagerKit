<?php

namespace CookieManagerKit\Consent;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{ConsentLogEntry, ConsentSubject};

final class DoctrineConsentLogStore implements ConsentLogStore
{
    public function __construct(private EntityManagerInterface $em) {}

    public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
    {
        // transactional(), not wrapInTransaction(): the installed ORM (2.14) has only the former on its interface.
        $id = $this->em->transactional(function () use ($choice, $now): string {
            $subject = $choice->subjectId === null ? null : $this->em->getRepository(ConsentSubject::class)->find($choice->subjectId);

            if (!$subject instanceof ConsentSubject) {
                $subject = new ConsentSubject();
                $this->em->persist($subject);
                // The database assigns the GUID on insert, and the entry needs it.
                $this->em->flush();
            } else {
                $subject->setDateUpdated($now);
            }

            $this->em->persist(
                (new ConsentLogEntry())
                    ->setSubject($subject)
                    ->setPolicyVersion($choice->policyVersion)
                    ->setCategories($choice->categories)
                    ->setTrigger($choice->trigger)
                    ->setLocale($choice->locale)
                    ->setUserAgentFamily($choice->userAgentFamily)
                    ->setCreatedAt($now->getTimestamp()),
            );
            $this->em->flush();

            return $subject->getID();
        });

        return (string) $id;
    }
}
