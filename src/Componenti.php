<?php

declare(strict_types=1);

namespace Topocodificatore;

/** Le parti di un indirizzo, come le ha capite AnalizzatoreIndirizzo o come arrivano dai parametri. */
final class Componenti
{
    public string $testo;
    public ?string $via;
    public ?string $civico;
    public ?string $localita;
    public ?string $cap;
    public ?string $comune;
    public ?string $provincia;

    public function __construct(
        string $testo = '',
        ?string $via = null,
        ?string $civico = null,
        ?string $localita = null,
        ?string $cap = null,
        ?string $comune = null,
        ?string $provincia = null
    ) {
        $this->testo = $testo;
        $this->via = $via;
        $this->civico = $civico;
        $this->localita = $localita;
        $this->cap = $cap;
        $this->comune = $comune;
        $this->provincia = $provincia;
    }

    /** @return array<string, string> */
    public function comeArray(): array
    {
        return array_filter([
            'via' => $this->via,
            'civico' => $this->civico,
            'localita' => $this->localita,
            'cap' => $this->cap,
            'comune' => $this->comune,
            'provincia' => $this->provincia,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
