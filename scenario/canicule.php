<?php
/**
 * ============================================================
 * SCÉNARIO SURVEILLANCE CANICULE
 * ============================================================
 *
 * Scénario Jeedom (bloc PHP) — à exécuter chaque soir à 23h30
 *
 * Fonction : Surveille la température extérieure et déclenche
 *            des alertes progressives par Telegram en cas de
 *            canicule (max >= 30°C ET min >= 20°C).
 *
 * Auteur   : Alexandre CHRETIEN
 * Contexte : Surveillance météo — Station Margny Anémomètre
 *
 * Déclencheur recommandé : 30 23 * * *
 * ============================================================
 *
 * IDs des commandes Jeedom à adapter :
 *   - Température extérieure : 6233  (Station Margny)
 *   - Bot Telegram           : 6039  (Alweddle)
 *
 * Variables Jeedom utilisées :
 *   - Compteur_Canicule : Nombre de jours consécutifs de canicule
 *   - canicule          : Niveau d'alerte actuel (0, 1, 2 ou 3)
 *
 * Critères canicule (définition météo France) :
 *   - Température maximale >= 30°C
 *   - Température minimale >= 20°C (nuit tropicale)
 * ============================================================
 */

// --- Helpers lecture/écriture variables Jeedom ---
function jeeGetVar($_name, $_default = '') {
    $ds = dataStore::byTypeLinkIdKey('scenario', -1, trim($_name));
    return is_object($ds) ? $ds->getValue($_default) : $_default;
}

function jeeSetVar($_name, $_value) {
    $ds = dataStore::byTypeLinkIdKey('scenario', -1, trim($_name));
    if (!is_object($ds)) {
        $ds = new dataStore();
        $ds->setType('scenario');
        $ds->setLink_id(-1);
        $ds->setKey(trim($_name));
    }
    $ds->setValue($_value);
    $ds->save();
}

try {
    $scenario->setLog("=== Surveillance Canicule ===");

    // --- Lecture des températures max/min du jour ---
    $debut = date('Y-m-d') . ' 00:00:00';
    $fin   = date('Y-m-d') . ' 23:59:59';

    $db = DB::getConnection();
    $q  = $db->prepare("SELECT MAX(CAST(value AS DECIMAL(10,2))) as tmax, MIN(CAST(value AS DECIMAL(10,2))) as tmin FROM history WHERE cmd_id = :cmd_id AND datetime BETWEEN :debut AND :fin");
    $q->bindValue(':cmd_id', 6233, PDO::PARAM_INT); // ← ID température extérieure
    $q->bindValue(':debut', $debut);
    $q->bindValue(':fin', $fin);
    $q->execute();
    $row = $q->fetch(PDO::FETCH_ASSOC);

    $temp_max = floatval($row['tmax']);
    $temp_min = floatval($row['tmin']);

    $scenario->setLog("Temp max : " . $temp_max . "°C | Temp min : " . $temp_min . "°C");

    // --- Lecture des variables actuelles ---
    $compteur  = intval(jeeGetVar('Compteur_Canicule', 0));
    $old_level = intval(jeeGetVar('canicule', 0));

    $scenario->setLog("Compteur : $compteur | Niveau actuel : $old_level");

    // --- Critères canicule : max >= 30°C ET min >= 20°C ---
    if ($temp_max >= 30 && $temp_min >= 20) {

        $compteur++;
        jeeSetVar('Compteur_Canicule', $compteur);
        $scenario->setLog("Critères canicule remplis → Compteur : $compteur");

        // --- Détermination du niveau ---
        if ($compteur == 1) {
            $new_level = 1;
        } elseif ($compteur == 2) {
            $new_level = 2;
        } else {
            $new_level = 3;
        }

        jeeSetVar('canicule', $new_level);

        // --- Notification uniquement si le niveau change ---
        if ($new_level != $old_level) {
            $scenario->setLog("Changement de niveau : $old_level → $new_level");

            if ($new_level == 1) {
                $message = "🌡️ Canicule à venir — Niveau 1\n🌤️ Max " . $temp_max . "°C | Min " . $temp_min . "°C\n⚠️ Prépare-toi, ça va chauffer !";
            } elseif ($new_level == 2) {
                $message = "🌡️🌡️ Canicule à venir — Niveau 2\n☀️ Max " . $temp_max . "°C | Min " . $temp_min . "°C\n⚠️ 2 jours consécutifs de forte chaleur.\n💧 Commence à t'hydrater davantage !";
            } else {
                $message = "🚨🔥 ALERTE CANICULE — Niveau 3\n🌡️ Max " . $temp_max . "°C | Min " . $temp_min . "°C\n💧 Hydrate-toi bien !\n🏠 Reste au frais et évite les sorties aux heures chaudes !\n😎 Prends soin de toi !";
            }

            cmd::byId(6039)->execCmd(['title' => '', 'message' => $message]); // ← ID Bot Telegram
            $scenario->setLog("Notification envoyée : niveau $new_level");

        } else {
            $scenario->setLog("Niveau inchangé ($new_level) → pas de notification.");
        }

    } else {

        $scenario->setLog("Critères non remplis → reset compteur");

        // --- Notification de fin de canicule si on sort d'une alerte ---
        if ($old_level > 0) {
            $message = "✅ Fin de canicule !\n🌤️ Max " . $temp_max . "°C | Min " . $temp_min . "°C\n😮‍💨 Retour à la normale, ouf !";
            cmd::byId(6039)->execCmd(['title' => '', 'message' => $message]); // ← ID Bot Telegram
            $scenario->setLog("Notification fin de canicule envoyée.");
        }

        jeeSetVar('Compteur_Canicule', 0);
        jeeSetVar('canicule', 0);
    }

    $scenario->setLog("=== Fin traitement canicule ===");

} catch (Exception $e) {
    $scenario->setLog("ERREUR : " . $e->getMessage());
}
