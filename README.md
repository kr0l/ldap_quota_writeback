# LDAP Quota Writeback

Kleine Nextcloud-App für die FFF-Cloud. Sie schreibt Quota-Änderungen aus der
Nextcloud-Benutzerverwaltung zurück ins LDAP.

## Warum

Die LDAP/AD-Integration liest die Quota aus dem LDAP-Attribut `roomNumber`
(Kontingent-Feld, Standard `1GB`). Der LDAP-Abgleich (Cron) und jede
Passwort-Anmeldung setzen die Quota auf den LDAP-Wert zurück. Änderungen in der
Oberfläche sprangen deshalb nach ein paar Stunden zurück. `ldap_write_support`
schreibt Quotas nicht ins LDAP.

## Was die App macht

Ändert ein Admin oder Gruppenadmin in Nextcloud die Quota eines LDAP-Benutzers,
schreibt die App den Wert in das Attribut, das in der LDAP/AD-Integration als
Kontingent-Feld eingestellt ist. LDAP bleibt die Quelle, und der Abgleich
übernimmt ab dann den neuen Wert.

| In Nextcloud gesetzt | Im LDAP                                               |
|----------------------|-------------------------------------------------------|
| `20 GB`              | `roomNumber: 20 GB`                                   |
| Unbegrenzt           | `roomNumber: none`                                    |
| Standard-Kontingent  | `roomNumber` wird gelöscht (LDAP-Standard `1GB` gilt) |

## Wann zurückgeschrieben wird (Sicherheit)

Nur wenn ein **angemeldeter Admin oder Gruppenadmin** die Quota **eines anderen
Kontos** ändert (Weboberfläche oder Provisioning-API), oder per
`occ ldap-quota:set`. Alles andere wird ignoriert:

- der LDAP-Abgleich und Anmeldungen (kein Admin angemeldet bzw. das Konto selbst),
- Apps, die Quotas automatisch setzen (z. B. Quota-Zuordnungen bei SAML/OpenID-Login),
- lokale Benutzer (nicht aus LDAP),
- ungültige Werte.

Weitere Eigenschaften:

- Es wird nur das Kontingent-Attribut des betroffenen Benutzers geändert, über
  die Verbindung der LDAP/AD-Integration. Die Werte sind vorher von Nextcloud
  geprüft (z. B. `20 GB`), es gibt keine Filter- oder DN-Bausteine aus Eingaben.
- Ist kein Kontingent-Feld eingestellt, macht die App nichts.
- Fehler (z. B. LDAP nicht erreichbar) landen im Nextcloud-Log (App
  `ldap_quota_writeback`). Die Quota-Änderung in Nextcloud selbst wird nie
  blockiert; ohne LDAP-Eintrag setzt der nächste Abgleich sie aber zurück.
- Die eigene Quota als Admin: per `occ ldap-quota:set` (die Oberfläche schreibt
  Änderungen am eigenen Konto bewusst nicht zurück).
- Gruppenadmins können – wie bisher in Nextcloud – Quotas ihrer Mitglieder
  setzen, jetzt eben dauerhaft.

## Befehle

```bash
sudo -u www-data php occ ldap-quota:show USERNAME
sudo -u www-data php occ ldap-quota:set USERNAME "20 GB"
```

`ldap-quota:set` akzeptiert auch `none` (unbegrenzt) und `default`.

**Nicht** `occ user:setting USERNAME files quota ...` benutzen: Das schreibt direkt
in die Datenbank, die App bekommt davon nichts mit, und der Abgleich setzt den
Wert wieder zurück.

## Installation

```bash
# Ordner nach /var/www/cloud/apps/ldap_quota_writeback kopieren, dann:
chown -R www-data:www-data /var/www/cloud/apps/ldap_quota_writeback
sudo -u www-data php /var/www/cloud/occ app:enable ldap_quota_writeback
```

Abschalten: `sudo -u www-data php occ app:disable ldap_quota_writeback`

## Bei Nextcloud-Upgrades

Die App hat bewusst keine echte Obergrenze (`max-version="99"`) und bleibt nach
Upgrades aktiv. Sie nutzt nur öffentliche Nextcloud-Schnittstellen (OCP) und fängt
Fehler ab, statt Nextcloud zu stören.

Nach jedem größeren Upgrade kurz an einem Testkonto prüfen:

```bash
sudo -u www-data php occ ldap-quota:set TESTKONTO "2 GB"
sudo -u www-data php occ ldap:check-user --update TESTKONTO
sudo -u www-data php occ ldap-quota:show TESTKONTO   # erwartet: 2 GB in Nextcloud und LDAP
sudo -u www-data php occ ldap-quota:set TESTKONTO default
grep ldap_quota_writeback /var/log/nextcloud/nextcloud.log | tail
```

Falls etwas nicht passt: `occ app:disable ldap_quota_writeback`. Sollte `occ`
wegen der App gar nicht mehr starten, den Ordner `apps/ldap_quota_writeback`
aus `apps/` wegschieben.

Einzige Ausnahme bei den Schnittstellen: Kontingent-Feld und Standardkontingent
liest die App aus den App-Einstellungen von `user_ldap` (`*ldap_quota_attr`,
`*ldap_quota_def`), weil es dafür keine Schnittstelle gibt. Ändert eine künftige
Version dieses Format, schreibt die App nichts mehr und meldet das als Warnung
im Log.
