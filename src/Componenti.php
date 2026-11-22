<?php

declare(strict_types=1);

namespace Topocodificatore;

/** Le parti di un indirizzo, come le ha capite AnalizzatoreIndirizzo o come arrivano dai parametri. */
final class Componenti
{
    public function __construct(
        public string $testo = '',
        public ?string $via = null,
        public ?string $civico = null,
        public ?string $localita = null,
        public ?string $cap = null,
        public ?string $comune = null,
        public ?string $provincia = null,
    ) {
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
