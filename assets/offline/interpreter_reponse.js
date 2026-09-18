/*
 * Lecture de la réponse de `POST /api/vente` — fonction pure, testable sous Node.
 *
 * Elle existe pour une raison précise : la file supprime une vente **dès qu'elle
 * croit le serveur l'avoir enregistrée**. Se tromper dans ce sens, c'est perdre
 * une vente sans que personne ne le sache — l'argent est dans le tiroir, la vente
 * n'existe nulle part.
 *
 * Le piège : sur une session expirée, le pare-feu ne répond pas 401 mais une
 * **redirection 302 vers /login**. `fetch` la suit tout seul, rejoue la requête en
 * GET, et reçoit la page de connexion avec un **200**. Pris pour un succès, ce 200
 * effaçait la vente de la file. La requête est donc émise avec
 * `redirect: 'manual'`, et seule une réponse **JSON portant `ok: true`** vaut
 * confirmation.
 *
 * @param {{status: number, type?: string, redirected?: boolean, json: () => Promise<any>}} reponse
 * @returns {Promise<{statut: number, corps: ?object}>}
 */
export async function interpreterReponse(reponse) {
    // Redirection non suivie (`opaqueredirect`, statut 0) ou 3xx : le serveur
    // renvoie vers la connexion. La session est à rouvrir, rien n'a été enregistré.
    if (reponse.type === 'opaqueredirect' || reponse.redirected || (reponse.status >= 300 && reponse.status < 400)) {
        return { statut: 401, corps: null };
    }

    let corps = null;
    try {
        corps = await reponse.json();
    } catch {
        // Corps non JSON (page HTML d'un proxy, d'un portail captif…).
    }

    if (reponse.status === 200 || reponse.status === 201) {
        // Un succès sans accusé lisible n'est pas un succès : on ne supprime rien.
        // 502 est temporaire pour la file, qui réessaiera — l'idempotence sur
        // l'uuid absorbe le doublon si le serveur avait en fait enregistré.
        if (corps?.ok !== true) {
            return { statut: 502, corps: null };
        }
    }

    return { statut: reponse.status, corps };
}
