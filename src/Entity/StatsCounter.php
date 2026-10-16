<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;

/**
 * One anonymous daily counter for a banner event. Rows are written only by StatsStore::increment() with a single
 * atomic upsert, which relies on the unique key below: without it concurrent visitors would create duplicate rows.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkStats",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_stats_key", columns={"statDay", "locale", "policyVersion", "metric"})},
 *      options={"comment": "Cookie banner statistics: anonymous daily counters"}
 * )
 */
class StatsCounter extends BaseEntity
{
    /**
     * @ORM\Column(type="string", length=10, nullable=false, options={"comment": "UTC day, YYYY-MM-DD"})
     */
    protected $statDay = '';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Resolved site locale"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "Policy version the event belongs to"})
     */
    protected $policyVersion = 1;

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"comment": "shown, accept_all, reject_all, custom or changed"})
     */
    protected $metric = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "How many times it happened that day"})
     */
    protected $total = 0;

    public function getStatDay(): string
    {
        return $this->statDay;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getPolicyVersion(): int
    {
        return $this->policyVersion;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function getTotal(): int
    {
        return $this->total;
    }
}
