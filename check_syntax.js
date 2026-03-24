
                    window.LM_RAPPORT = {
                        interventionId: null,
                        clientEmail: null,
                        nomSociete: null,
                        legal: {
                            address: null,
                            contact: null,
                            siret: null
                        },
                        dateInt: null,
                        csrfToken: null,
                        techName: null,
                        arc: null,
                        synth: {
                            tech: null,
                            date: null,
                            duree: null,
                            nbMachines: null,
                            ok: null,
                            aa: null,
                            nc: null,
                            nr: null,
                            na: null,
                            score: null,
                            nbMachinesFilled: null,
                            nbMachinesEmpty: null
                        },
                        sigTech: null,
                        sigClient: null,
                        pdfFilename: null, 
                        emptyFichesOption: 'exclude',
                        emptyMachinesIds: null,
                        machinesIds: [null],
                        machinesData: <?= json_encode(array_values(array_map(function($m) use ($intervention) {
                            return [
                                'id' => $m['id'],
                                'arc' => $intervention['numero_arc'],
                                'of' => $m['numero_of'] ?? '',
                                'designation' => $m['designation'] ?? '',
                                'annee' => $m['annee_fabrication'] ?? '',
                                'points_count' => $m['points_count'] ?? 0
                            ];
                        }, $machines))) ?>
                    };
                