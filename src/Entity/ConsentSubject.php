<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\UpdatedGuidEntity;

/**
 * One browser. Its GUID is the visitor's consent ID, kept in the consent cookie. `date_updated` is the time of the
 * latest decision and drives retention. Holds nothing else: no address, no user agent.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkConsentSubjects",
 *      options={"comment": "Cookie Manager consent log: one row per browser"}
 * )
 */
class ConsentSubject extends UpdatedGuidEntity {}
