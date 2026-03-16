
                    window.LM_RAPPORT = {
                        interventionId: <?= (int) $id ?>,
                        clientEmail: <?= json_encode($intervention['contact_email'] ?? $intervention['c_email'] ?? '') ?>,
                        nomSociete: <?= json_encode($intervention['nom_societe'] ?? '') ?>,
                        legal: {
                            address: <?= json_encode(COMPANY_LEGAL_ADDRESS) ?>,
                            contact: <?= json_encode(COMPANY_LEGAL_CONTACT) ?>,
                            siret: <?= json_encode(COMPANY_LEGAL_SIRET) ?>
                        },
                        dateInt: <?= json_encode(date('d/m/Y', strtotime($intervention['date_intervention'] ?? 'now'))) ?>,
                        csrfToken: <?= json_encode(getCsrfToken()) ?>,
                        techName: <?= json_encode($techName) ?>,
                        arc: <?= json_encode($intervention['numero_arc'] ?? '') ?>,
                        synth: {
                            tech: <?= json_encode($techName) ?>,
                            date: <?= json_encode(date('d/m/Y', strtotime($intervention['date_intervention'] ?? 'now'))) ?>,
                            duree: <?= json_encode($dureeSynth) ?>,
                            nbMachines: <?= count($machines) ?>,
                            ok: <?= $totalOk ?>,
                            aa: <?= $totalAmeliorer ?>,
                            nc: <?= $totalNonConforme ?>,
                            nr: <?= $totalRemplacer ?>,
                            na: <?= $totalNA ?>,
                            score: <?= $scoreConformite ?>,
                            nbMachinesFilled: <?= $nbMachinesFilled ?>,
                            nbMachinesEmpty: <?= $nbMachinesEmpty ?>
                        },
                        sigTech: <?= json_encode($intervention['signature_technicien'] ?? '') ?>,
                        sigClient: <?= json_encode($intervention['signature_client'] ?? '') ?>,
                        pdfFilename: <?= json_encode('Rapport_Lenoir_Mec_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $intervention['numero_arc'] ?? 'rapport') . '_' . date('d-m-Y') . '.pdf') ?>, 
                        machinesIds: [<?= implode(',', array_column($machines, 'id')) ?>],
                        machinesData: <?= json_encode(array_values(array_map(function($m) use ($intervention) {
                            return [
                                'arc' => $intervention['numero_arc'],
                                'of' => $m['numero_of'] ?? '',
                                'designation' => $m['designation'] ?? '',
                                'annee' => $m['annee_fabrication'] ?? '',
                                'points_count' => $m['points_count'] ?? 0
                            ];
                        }, $machines))) ?>
                    };
                
