<?php
/*
 * Language File: French
 *
 * Paste https://github.com/boxlabss/PASTE
 * demo: https://paste.boxlabs.uk/
 *
 * https://phpaste.sourceforge.io/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See LICENCE for more details.
 */

$lang = array();

$lang['banned'] = "Vous avez été banni de " . $site_name;
$lang['expired']        = "Le paste recherché a expiré.";
$lang['pleaseregister'] = "<br><br> <a class=\"btn btn-default\" href=\"login.php\">Connectez-vous</a> ou <a class=\"btn btn-default\" href=\"login.php?action=signup\">inscrivez-vous</a> pour créer un nouveau paste. C’est gratuit.";
$lang['registertoedit'] = "<a class=\"btn btn-default\" href=\"login.php\">Connectez-vous</a> ou <a class=\"btn btn-default\" href=\"login.php?action=signup\">inscrivez-vous</a> pour modifier ou forker ce paste. C’est gratuit.";
$lang['editpaste']		= "Éditer";
$lang['forkpaste']		= "Dupliquer";
$lang['guestmsgbody'] 	= "<a href=\"login.php\">Connectez-vous</a> ou <a href=\"login.php?action=signup\">inscrivez-vous</a> pour modifier, supprimer et suivre vos pastes, et bien plus.";
$lang['emptypastebin'] 	= "Aucun paste à afficher.";
$lang['siteprivate']	= "Ce pastebin est privé. <a class=\"btn btn-default\" href=\"login.php\">Connexion</a>";
$lang['image_wrong']    = "Erreur de captcha.";
$lang['missing-input-response'] = "Le paramètre de réponse reCAPTCHA est manquant. Veuillez vérifier les paramètres de PASTE.";
$lang['missing-input-secret'] = "Le secret reCAPTCHA est manquant. Ajoutez-le dans les paramètres de PASTE.";
$lang['invalid-input-response'] = "La réponse reCAPTCHA est invalide. Veuillez réessayer.";
$lang['invalid-input-secret'] = "Le secret reCAPTCHA est invalide ou mal formé. Vérifiez vos paramètres PASTE.";
$lang['empty_paste'] = "Vous ne pouvez pas publier un paste vide.";
$lang['large_paste'] = "Votre paste est trop volumineux. Taille maximale : " . $pastelimit . " Mo";
$lang['paste_db_error'] = "Impossible d’enregistrer dans la base de données.";
$lang['guestprivate'] = "Les pastes privés nécessitent un compte. Connectez-vous, ou choisissez Public ou Non répertorié.";
$lang['reservedname'] = "Ce nom de compte est réservé, il ne peut pas servir à se connecter.";
$lang['error'] = "Une erreur s’est produite.";
$lang['archive'] = "Archive";
$lang['archives'] = "Archive des pastes";
$lang['archivestitle'] = "Cette page contient les 100 pastes publics les plus récents.";
$lang['contact'] = "Contactez-nous";
$lang['full_name'] = "Nom complet";
$lang['email'] = "E-mail";
$lang['email_invalid'] = "Votre adresse e-mail semble invalide.";
$lang['message'] = "Le message est requis.";
$lang['login/register'] = "Connexion ou inscription";
$lang['rememberme'] = "Rester connecté.";
$lang['mail_acc_con'] = "Confirmation de compte $site_name";
$lang['mail_suc'] = "Le code de vérification a été envoyé à votre adresse e-mail.";
$lang['email_ver'] = "E-mail déjà vérifié.";
$lang['email_not'] = "E-mail introuvable.";
$lang['pass_change'] = "Un lien a été envoyé à votre e-mail.";
$lang['notverified'] = "Compte non vérifié.";
$lang['incorrect'] = "Nom d’utilisateur/mot de passe incorrect";
$lang['missingfields'] = "Tous les champs doivent être remplis.";
$lang['userexists'] = "Nom d’utilisateur déjà pris.";
$lang['emailexists'] = "E-mail déjà enregistré.";
$lang['registered'] = "Votre compte a été enregistré avec succès.";
$lang['usrinvalid'] = "Le nom d’utilisateur ne peut contenir que des lettres et des chiffres.";
$lang['mypastes'] = "Mes pastes";
$lang['pastedeleted'] = "Paste supprimé.";
$lang['databaseerror'] = "Impossible d’enregistrer dans la base de données.";
$lang['userchanged'] = "Nom d’utilisateur modifié avec succès.";
$lang['usernotvalid'] = "Nom d’utilisateur non valide.";
$lang['privatepaste'] = "Ceci est un paste privé.";
$lang['wrongpassword'] = "Mot de passe incorrect.";
$lang['pwdprotected'] = "Paste protégé par mot de passe";
$lang['notfound'] = "404 Introuvable";
$lang['wrongpwd'] = "Mot de passe incorrect. Réessayez.";
$lang['myprofile'] = "Mon profil";
$lang['profileerror'] = "Impossible de mettre à jour les informations du profil";
$lang['profileupdated'] = "Informations du profil mises à jour";
$lang['oldpasswrong'] = "Votre ancien mot de passe est incorrect.";
$lang['pastetitle'] = "Titre";
$lang['pastetime'] = "Publié le";
$lang['pastesyntax'] = "Syntaxe";
$lang['pasteviews'] = "Vues";
$lang['wentwrong'] = "Une erreur s’est produite.";
$lang['versent'] = "Un e-mail de vérification a été envoyé à votre adresse.";
$lang['modpaste'] = "Modifier ou forker";
$lang['newpaste'] = "Nouveau paste";
$lang['highlighting'] = "Coloration syntaxique";
$lang['expiration'] = "Expiration du paste";
$lang['visibility'] = "Visibilité du paste";
$lang['pwopt'] = "Mot de passe (facultatif)";
$lang['encrypt'] = "Tous les pastes sont chiffrés en base avec AES-256-CBC";
$lang['entercode'] = "Entrez le CAPTCHA";
$lang['almostthere'] = "On y est presque. Plus qu’une étape.";
$lang['username'] = "Nom d’utilisateur";
$lang['autogen'] = "Nom généré automatiquement";
$lang['setuser'] = "Définissez votre nom d’utilisateur";
$lang['keepuser'] = "Conserver le nom auto-généré ? Vous pourrez le changer une fois.";
$lang['enterpwd'] = "Entrez le mot de passe";
$lang['totalpastes'] = "Total des pastes :";
$lang['membtype'] = "Type de compte :";
$lang['chgpwd'] = "Changer le mot de passe";
$lang['curpwd'] = "Mot de passe actuel";
$lang['newpwd'] = "Nouveau mot de passe";
$lang['confpwd'] = "Confirmez le mot de passe";
$lang['viewpastes'] = "Voir tous mes pastes";
$lang['recentpastes'] = "Pastes récents";
$lang['user_public_pastes'] = " — pastes de l’utilisateur";
$lang['yourpastes'] = "Vos pastes";
$lang['mypastestitle'] = "Tous vos pastes, au même endroit.";
$lang['delete'] = "Supprimer";
$lang['highlighted'] = "Le texte ci-dessous est sélectionné. Appuyez sur Ctrl+C pour copier (&#8984;+C sur Mac).";
$lang['download'] = "Télécharger";
$lang['showlineno'] = "Afficher/Masquer les numéros de ligne";
$lang['copyto'] = "Copier le texte dans le presse-papiers";
$lang['rawpaste'] = "Texte brut";
$lang['membersince'] = "Membre depuis : ";
$lang['delete_error_invalid'] = "Erreur : paste non supprimé car il n’existe pas ou ne vous appartient pas.";
$lang['deleteaccount'] = "Supprimer mon compte";
$lang['deletewarn'] = "Cela supprimera définitivement votre compte et tous vos pastes, commentaires, fichiers envoyés et albums. Action irréversible.";
$lang['typedelete'] = "Tapez DELETE pour confirmer.";
$lang['confirmdeletehint'] = "Vous devez taper DELETE (en majuscules).";
$lang['cancel'] = "Annuler";
$lang['confirmdelete'] = "Confirmer la suppression";
$lang['wentwrong'] = "Une erreur s’est produite.";
$lang['invalidtoken'] = "Jeton CSRF invalide.";
$lang['not_logged_in'] = "Erreur : vous devez être connecté pour faire cela.";
$lang['public'] = "Public";
$lang['unlisted'] = "Non répertorié";
$lang['private'] = "Privé";
$lang['hello'] = "Bonjour";
$lang['profile-message'] = "Voici votre page de profil où vous pouvez gérer vos pastes. Vos pastes publics, privés et non répertoriés apparaîtront ici. Vous pouvez aussi les supprimer. Les autres ne verront que vos pastes publics.";
$lang['profile-stats'] = "Quelques statistiques :";
$lang['profile-total-pastes'] = "Total des pastes :";
$lang['profile-total-pub'] = "Total des pastes publics :";
$lang['profile-total-unl'] = "Total des pastes non répertoriés :";
$lang['profile-total-pri'] = "Total des pastes privés :";
$lang['profile-total-views'] = "Total des vues de vos pastes :";
$lang['embed-hosted-by'] = "hébergé par";
$lang['view-raw'] = "Voir brut";
$lang['my_account'] = "Mon compte";
$lang['guest'] = "Invité";
$lang['login'] = "Connexion";
$lang['signup'] = "Inscription";
$lang['forgot_password'] = "Mot de passe oublié";
$lang['resend_verification'] = "Renvoyer l’e-mail de vérification";
$lang['or_login_with'] = "Ou se connecter avec";
$lang['login_with_google'] = "Google";
$lang['login_with_facebook'] = "Facebook";
$lang['already_have_account'] = "Vous avez déjà un compte ?";
$lang['reset_password'] = "Réinitialiser le mot de passe";
$lang['new_password'] = "Nouveau mot de passe";
$lang['send_reset_link'] = "Envoyer le lien de réinitialisation";
$lang['email_verified'] = "E-mail vérifié avec succès. Vous pouvez maintenant vous connecter.";
$lang['invalid_code'] = "Code invalide ou expiré.";
$lang['pass_reset'] = "Réinitialisation réussie. Vous pouvez maintenant vous connecter.";
$lang['mail_error'] = "Échec de l’envoi de l’e-mail.";
$lang['settings'] = "Paramètres";
$lang['logout'] = "Déconnexion";
$lang['49'] = "49";
$lang['50'] = "50";
$lang['account_suspended'] = "Compte suspendu";
$lang['ajax_error'] = "Erreur Ajax";
$lang['createpaste'] = "Créer un paste";
$lang['email_not_verified'] = "E-mail non vérifié";
$lang['expired'] = "Expiré";
$lang['forgot'] = "Oublié";
$lang['fullname'] = "Nom complet";
$lang['guestmsgtitle'] = "Bonjour, invité ! Paste est destiné au code source et aux textes de débogage.";
$lang['guestwelcome'] = "Bienvenue, invité";
$lang['invalid_credentials'] = "Identifiants invalides";
$lang['invalid_email'] = "E-mail invalide";
$lang['invalid_reset_code'] = "Code de réinitialisation invalide";
$lang['invalid_state'] = "État invalide";
$lang['invalid_username'] = "Nom d’utilisateur invalide";
$lang['login_required'] = "Connexion requise";
$lang['login_success'] = "Connexion réussie";
$lang['low_score'] = "Score faible";
$lang['my-pastes'] = "Mes pastes";
$lang['no_results'] = "Aucun résultat";
$lang['password'] = "Mot de passe";
$lang['password_reset_success'] = "Réinitialisation du mot de passe réussie";
$lang['password_too_short'] = "Mot de passe trop court";
$lang['pastemember'] = "Membre";
$lang['pastes'] = "Pastes";
$lang['recaptcha_error'] = "Erreur reCAPTCHA";
$lang['recaptcha_failed'] = "reCAPTCHA n’a pas pu vérifier que vous n’êtes pas un robot. Actualisez et réessayez.";
$lang['recaptcha_missing'] = "reCAPTCHA manquante";
$lang['recaptcha_timeout'] = "Délai d’attente reCAPTCHA dépassé";
$lang['resend'] = "Renvoyer";
$lang['search'] = "Rechercher";
$lang['search_results_for'] = "Résultats de recherche pour";
$lang['signup_success'] = "Inscription réussie";
$lang['sort'] = "Trier";
$lang['sort_code_asc'] = "Code ↑";
$lang['sort_code_desc'] = "Code ↓";
$lang['sort_date_asc'] = "Date ↑";
$lang['sort_date_desc'] = "Date ↓";
$lang['sort_title_asc'] = "Titre ↑";
$lang['sort_title_desc'] = "Titre ↓";
$lang['sort_views_asc'] = "Vues ↑";
$lang['sort_views_desc'] = "Vues ↓";
$lang['submit_error'] = "Erreur d’envoi";
$lang['user_exists'] = "L’utilisateur existe déjà";
$lang['views'] = "Vues";
$lang['charsleft'] = "caractères restants";
$lang['cancel'] = "Annuler";
$lang['postreply'] = "Publier la réponse";
$lang['loginreply'] = "Se connecter pour répondre";
$lang['reply'] = "Répondre";
$lang['logintocomment'] = "Connectez-vous pour participer à la discussion.";
$lang['nocomments'] = "Aucun commentaire pour l’instant. Soyez le premier.";
$lang['commentexplain'] = "Markdown est désactivé ; les liens seront automatiquement reconnus.";
$lang['comments'] = "Commentaires";
$lang['postcomment'] = "Publier un commentaire";
$lang['logintocomment'] = "Connectez-vous pour publier un commentaire.";
$lang['commentsdisabled'] = "Les commentaires sont désactivés.";
$lang['postedon'] = "Publié le :";
$lang['size'] = "Taille :";
$lang['detected'] = "Détecté";
$lang['viewdifferences'] = "Voir les différences";
$lang['loadraw'] = "Charger le brut";
$lang['detectedexplainlabel'] = "Comment nous avons détecté la langue";

?>
