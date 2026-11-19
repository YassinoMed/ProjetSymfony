<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\AgentName;
use App\Entity\Enum\RunStatus;
use App\Repository\AiRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Trace d'exécution d'un agent pour une demande donnée.
 *
 * Chaque exécution est horodatée et conserve le fournisseur, le modèle, la
 * latence et les tokens consommés : la proposition reste explicable même si
 * le fournisseur change ou devient indisponible.
 */
#[ORM\Entity(repositoryClass: AiRunRepository::class)]
#[ORM\Table(name: 'ai_runs')]
#[ORM\Index(name: 'idx_ai_runs_request', columns: ['request_id', 'started_at'])]
class AiRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private AiRequest $request;

    #[ORM\Column(length: 32, enumType: AgentName::class)]
    private AgentName $agent;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'latency_ms', nullable: true)]
    private ?int $latencyMs = null;

    #[ORM\Column(name: 'input_tokens', nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(name: 'output_tokens', nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(AiRequest $request, AgentName $agent)
    {
        $this->id = Uuid::v7();
        $this->request = $request;
        $this->agent = $agent;
        $this->status = RunStatus::Failed;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequest(): AiRequest
    {
        return $this->request;
    }

    public function getAgent(): AgentName
    {
        return $this->agent;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function succeed(
        string $summary,
        ?string $provider = null,
        ?string $model = null,
        ?int $latencyMs = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): void {
        $this->status = RunStatus::Succeeded;
        $this->summary = mb_substr($summary, 0, 2000);
        $this->provider = null === $provider ? null : mb_substr($provider, 0, 64);
        $this->model = null === $model ? null : mb_substr($model, 0, 120);
        $this->latencyMs = $latencyMs;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->error = null;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = RunStatus::Failed;
        $this->error = mb_substr($error, 0, 2000);
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return [
            'agent' => $this->agent->value,
            'label' => $this->agent->label(),
            'status' => $this->status->value,
            'provider' => $this->provider,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'summary' => $this->summary,
            'error' => $this->error,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'finished_at' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
