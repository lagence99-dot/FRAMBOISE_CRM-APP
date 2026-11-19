# Format d'échange application → extension WordPress (v1.2)

CSV séparé par `;`, encodé UTF-8 avec BOM, une ligne par personne.

| Colonne | Contenu |
|---|---|
| company, first_name, last_name, role, email, phone | Coordonnées |
| sector, activity | Secteur et métier (le secteur est rangé dans la liste de l'extension) |
| source | Prospection WhatsApp / SMS / e-mail, LinkedIn, BNI |
| status | prospect, contacte, rdv, qualifie, devis, negociation, signe, perdu |
| entity | framboise ou vide |
| project, problem, need, website, address, city, linkedin, source_url | Contexte |
| channels, first_contact, last_contact | Canaux utilisés et dates (AAAA-MM-JJ) |
| replied, replied_date, optout, optout_date | « A répondu » et « Ne plus contacter » (oui/non + date) |
| notes, next_action, next_date | Suivi |
| prospect_messages | `AAAA-MM-JJ \| Canal \| texte` séparés par ` \|\| ` |
| exchanges | `AAAA-MM-JJ \| type \| note` séparés par ` \|\| ` (types : appel, whatsapp, sms, email, rdv, proposition, devis, prospection, reponse, autre) |

Règles d'import : rapprochement par téléphone (9 derniers chiffres), puis e-mail, puis nom d'entreprise ; aucun doublon d'échange ; « a répondu » et « ne plus contacter » ne sont jamais décochés ; l'état ne fait qu'avancer (sauf vers « perdu »).
