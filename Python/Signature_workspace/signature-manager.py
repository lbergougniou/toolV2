# app.py - Application pour la gestion des signatures email dans Google Workspace
# Cette application permet aux administrateurs de gérer les signatures email des utilisateurs

from flask import Flask, render_template, request, redirect, url_for, flash, jsonify, session
from google.oauth2 import service_account
from googleapiclient.discovery import build
import os
import json
import base64
import secrets
import re
import requests
import uuid
from bs4 import BeautifulSoup
from urllib.parse import urlparse
import logging

# Configuration du logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)
# Supprimer les avertissements liés au file_cache
logging.getLogger('googleapiclient.discovery_cache').setLevel(logging.ERROR)

# Initialisation de l'application Flask
app = Flask(__name__)
app.secret_key = secrets.token_hex(16)  # Clé secrète aléatoire pour les sessions

# ----- CONFIGURATION -----
# Ces paramètres doivent être adaptés à votre environnement
SERVICE_ACCOUNT_FILE = 'client_secret.json'  # Fichier JSON du compte de service
ADMIN_EMAIL = 'luc@scorimmo.com'  # Email de l'administrateur qui délègue l'autorité
DOMAIN = 'scorimmo.com'  # Domaine Google Workspace
EXTERNAL_URL = None  # URL externe pour accéder à l'application, à configurer si nécessaire
ENABLE_GROUPS = False  # Activer/désactiver la fonctionnalité des groupes

# Scopes d'API nécessaires pour le fonctionnement de l'application
SCOPES = [
    'https://www.googleapis.com/auth/admin.directory.user',      # Lister les utilisateurs
    'https://www.googleapis.com/auth/gmail.settings.basic',      # Modifier les paramètres Gmail
    'https://www.googleapis.com/auth/gmail.settings.sharing',    # Modifier les paramètres des adresses secondaires
    'https://mail.google.com/'                                   # Accès complet à Gmail (pour les cas difficiles)
]

# Ajouter le scope des groupes si la fonctionnalité est activée
if ENABLE_GROUPS:
    SCOPES.append('https://www.googleapis.com/auth/admin.directory.group.readonly')

# ----- FONCTIONS D'AUTHENTIFICATION -----

def get_credentials():
    """
    Obtient les identifiants du compte de service avec délégation à l'administrateur.
    
    Returns:
        Credentials ou None: L'objet d'identifiants délégués ou None en cas d'erreur
    """
    try:
        # Charger les identifiants à partir du fichier JSON
        credentials = service_account.Credentials.from_service_account_file(
            SERVICE_ACCOUNT_FILE, scopes=SCOPES)
        
        # Déléguer l'autorité à l'administrateur
        delegated_credentials = credentials.with_subject(ADMIN_EMAIL)
        return delegated_credentials
    except Exception as e:
        logger.error(f"Erreur d'authentification: {e}")
        return None

def get_admin_service():
    """
    Crée un service Admin SDK pour la gestion des utilisateurs.
    
    Returns:
        Service ou None: L'objet service Admin SDK ou None en cas d'erreur
    """
    credentials = get_credentials()
    if credentials:
        return build('admin', 'directory_v1', credentials=credentials)
    return None

def get_gmail_service(user_email=None):
    """
    Crée un service Gmail pour un utilisateur spécifique ou l'admin par défaut.
    
    Args:
        user_email (str, optional): L'email de l'utilisateur ciblé. Si None, utilise l'admin.
        
    Returns:
        Service ou None: L'objet service Gmail ou None en cas d'erreur
    """
    credentials = get_credentials()
    if not credentials:
        return None
        
    if user_email and user_email != ADMIN_EMAIL:
        # Pour agir au nom d'un utilisateur spécifique
        try:
            user_credentials = credentials.with_subject(user_email)
            return build('gmail', 'v1', credentials=user_credentials)
        except Exception as e:
            logger.error(f"Erreur lors de la création du service Gmail pour {user_email}: {e}")
            # Fallback vers le service admin en cas d'échec
            return build('gmail', 'v1', credentials=credentials)
    else:
        # Utiliser l'admin par défaut
        return build('gmail', 'v1', credentials=credentials)

# ----- FONCTIONS DE TRAITEMENT DES IMAGES -----

def extract_and_save_images(html_content, static_folder):
    """
    Extrait les images d'un HTML et les enregistre localement.
    
    Args:
        html_content (str): Le contenu HTML de la signature
        static_folder (str): Le chemin vers le dossier static de Flask
        
    Returns:
        str: Le HTML modifié avec les chemins locaux des images
    """
    if not html_content:
        return html_content
    
    # Utiliser BeautifulSoup pour parser le HTML
    soup = BeautifulSoup(html_content, 'html.parser')
    
    # Trouver toutes les balises img
    img_tags = soup.find_all('img')
    
    # Créer le dossier pour les images si nécessaire
    upload_folder = os.path.join(static_folder, 'uploads')
    os.makedirs(upload_folder, exist_ok=True)
    
    for img in img_tags:
        # Vérifier si l'image a une source
        if img.get('src'):
            img_src = img['src']
            
            # Si l'image est déjà dans notre dossier uploads, on la laisse
            if img_src.startswith('/static/uploads/'):
                continue
                
            # Traitement différent selon le type de source
            if img_src.startswith('data:image'):
                # Image en base64
                try:
                    # Extraire le type et les données
                    img_format = img_src.split(';')[0].split('/')[1]
                    img_data = img_src.split(',')[1]
                    
                    # Générer un nom de fichier unique
                    filename = f"{uuid.uuid4()}.{img_format}"
                    file_path = os.path.join(upload_folder, filename)
                    
                    # Décoder et enregistrer l'image
                    with open(file_path, 'wb') as f:
                        f.write(base64.b64decode(img_data))
                    
                    # Mettre à jour la source de l'image
                    img['src'] = f"/static/uploads/{filename}"
                except Exception as e:
                    logger.error(f"Erreur lors de l'extraction de l'image base64: {e}")
            
            elif img_src.startswith(('http://', 'https://')):
                # Image externe
                try:
                    # Analyser l'URL pour obtenir le nom de fichier
                    parsed_url = urlparse(img_src)
                    original_filename = os.path.basename(parsed_url.path)
                    
                    # Générer un nom de fichier unique tout en préservant l'extension
                    ext = os.path.splitext(original_filename)[1] or '.jpg'
                    if not ext.startswith('.'):
                        ext = f".{ext}"
                    filename = f"{uuid.uuid4()}{ext}"
                    file_path = os.path.join(upload_folder, filename)
                    
                    # Télécharger l'image
                    response = requests.get(img_src, timeout=5)
                    if response.status_code == 200:
                        with open(file_path, 'wb') as f:
                            f.write(response.content)
                        
                        # Mettre à jour la source de l'image
                        img['src'] = f"/static/uploads/{filename}"
                except Exception as e:
                    logger.error(f"Erreur lors du téléchargement de l'image {img_src}: {e}")
    
    # Retourner le HTML modifié
    return str(soup)

def prepare_html_for_gmail(html_content, request):
    """
    Prépare le HTML pour Gmail en convertissant les chemins relatifs d'images en URLs absolues.
    
    Args:
        html_content (str): Le contenu HTML de la signature
        request (Request): L'objet request de Flask pour obtenir l'URL de base
        
    Returns:
        str: Le HTML modifié avec des URLs absolues pour les images
    """
    if not html_content:
        return html_content
    
    # Utiliser l'URL externe configurée ou l'URL de la requête
    base_url = EXTERNAL_URL or request.host_url.rstrip('/')
    
    # Utiliser BeautifulSoup pour parser le HTML
    soup = BeautifulSoup(html_content, 'html.parser')
    
    # Trouver toutes les balises img
    img_tags = soup.find_all('img')
    
    for img in img_tags:
        # Vérifier si l'image a une source et qu'elle est relative
        if img.get('src') and img['src'].startswith('/static/'):
            # Convertir le chemin relatif en URL absolue
            img['src'] = f"{base_url}{img['src']}"
    
    # Retourner le HTML modifié
    return str(soup)

def preserve_image_paths(current_signature, new_signature):
    """
    Préserve les chemins des images existantes lors de la mise à jour d'une signature.
    
    Args:
        current_signature (str): La signature actuelle
        new_signature (str): La nouvelle signature
        
    Returns:
        str: La nouvelle signature avec les chemins d'images préservés
    """
    if not (current_signature and new_signature and '<img' in current_signature and '<img' in new_signature):
        return new_signature
        
    # Parser les signatures
    soup_old = BeautifulSoup(current_signature, 'html.parser')
    soup_new = BeautifulSoup(new_signature, 'html.parser')
    
    # Créer un dictionnaire des chemins d'images actuels
    current_image_paths = {}
    for img in soup_old.find_all('img'):
        if img.get('src') and (img['src'].startswith('/static/uploads/') or img['src'].startswith('http')):
            # Utiliser l'attribut alt ou src comme clé
            key = img.get('alt', '') or img['src']
            current_image_paths[key] = img['src']
    
    # Mettre à jour les images dans la nouvelle signature
    for img in soup_new.find_all('img'):
        if img.get('src'):
            if img['src'].startswith('data:image'):
                # Nouvelle image en base64, on la traite normalement
                continue
            elif img.get('alt') and img['alt'] in current_image_paths:
                # Utiliser le chemin préservé
                img['src'] = current_image_paths[img['alt']]
            elif img['src'] in current_image_paths:
                # Correspondance directe
                img['src'] = current_image_paths[img['src']]
    
    # Retourner le HTML modifié
    return str(soup_new)

# ----- FONCTIONS DE GESTION DES SIGNATURES -----

def update_signature(user_email, target_email, html_signature, request):
    """
    Met à jour la signature d'une adresse email en essayant plusieurs méthodes.
    
    Args:
        user_email (str): L'email de l'utilisateur
        target_email (str): L'adresse d'envoi à modifier
        html_signature (str): Le contenu HTML de la signature
        request (Request): L'objet request de Flask
        
    Returns:
        tuple: (success, error_message)
    """
    # Préparer le HTML pour Gmail avec des URLs absolues
    html_for_gmail = prepare_html_for_gmail(html_signature, request)
    
    # Liste des méthodes à essayer dans l'ordre
    methods = [
        # Méthode 1: Via l'utilisateur directement
        {
            'description': 'via le compte utilisateur',
            'function': lambda: get_gmail_service(user_email).users().settings().sendAs().patch(
                userId='me',
                sendAsEmail=target_email,
                body={'signature': html_for_gmail}
            ).execute()
        },
        # Méthode 2: Via l'admin agissant sur l'utilisateur
        {
            'description': 'via le compte administrateur',
            'function': lambda: get_gmail_service(ADMIN_EMAIL).users().settings().sendAs().patch(
                userId=user_email,
                sendAsEmail=target_email,
                body={'signature': html_for_gmail}
            ).execute()
        },
        # Méthode 3: Directement via les credentials admin sans délégation supplémentaire
        {
            'description': 'via les credentials directs',
            'function': lambda: build('gmail', 'v1', credentials=get_credentials()).users().settings().sendAs().patch(
                userId=user_email,
                sendAsEmail=target_email,
                body={'signature': html_for_gmail}
            ).execute()
        }
    ]
    
    # Essayer chaque méthode jusqu'à ce qu'une fonctionne
    error_messages = []
    for method in methods:
        try:
            method['function']()
            return True, f"Signature mise à jour avec succès pour {target_email} ({method['description']}) !"
        except Exception as e:
            error_message = f"Échec {method['description']}: {str(e)}"
            error_messages.append(error_message)
            logger.warning(error_message)
    
    # Si on arrive ici, toutes les méthodes ont échoué
    return False, error_messages

def get_user_send_as_addresses(user_email):
    """
    Récupère la liste des adresses d'envoi d'un utilisateur.
    
    Args:
        user_email (str): L'email de l'utilisateur
        
    Returns:
        list: Liste des adresses d'envoi
    """
    try:
        gmail_service = get_gmail_service(user_email)
        if not gmail_service:
            return []
            
        send_as_response = gmail_service.users().settings().sendAs().list(userId='me').execute()
        return send_as_response.get('sendAs', [])
    except Exception as e:
        logger.error(f"Erreur lors de la récupération des adresses d'envoi pour {user_email}: {e}")
        return []

def get_signature_for_email(user_email, email_address):
    """
    Récupère la signature d'une adresse email spécifique.
    
    Args:
        user_email (str): L'email de l'utilisateur
        email_address (str): L'adresse d'envoi
        
    Returns:
        str: La signature HTML ou une chaîne vide
    """
    try:
        gmail_service = get_gmail_service(user_email)
        if not gmail_service:
            return ""
            
        send_as = gmail_service.users().settings().sendAs().get(
            userId='me', 
            sendAsEmail=email_address
        ).execute()
        return send_as.get('signature', '')
    except Exception as e:
        logger.error(f"Erreur lors de la récupération de la signature pour {email_address}: {e}")
        return ""

# ----- FONCTIONS DE MISE À JOUR DES UTILISATEURS -----

def update_user_info(user_email, user_data):
    """
    Met à jour les informations d'un utilisateur dans Google Workspace.
    
    Args:
        user_email (str): L'email de l'utilisateur à mettre à jour
        user_data (dict): Dictionnaire contenant les données à mettre à jour
        
    Returns:
        tuple: (success, message)
    """
    try:
        admin_service = get_admin_service()
        if not admin_service:
            return False, "Impossible de se connecter au service Admin Google"
        
        # Récupérer les informations actuelles de l'utilisateur
        current_user_info = admin_service.users().get(userKey=user_email).execute()
        
        # Préparer l'objet de mise à jour
        update_body = {}
        
        # Mettre à jour le nom (prénom et nom)
        if 'firstName' in user_data or 'lastName' in user_data:
            name = current_user_info.get('name', {})
            if 'firstName' in user_data:
                name['givenName'] = user_data['firstName']
            if 'lastName' in user_data:
                name['familyName'] = user_data['lastName']
            
            if 'givenName' in name and 'familyName' in name:
                name['fullName'] = f"{name['givenName']} {name['familyName']}"
                
            update_body['name'] = name
        
        # Mettre à jour le téléphone
        if 'phoneNumber' in user_data:
            phones = current_user_info.get('phones', [])
            if phones:
                # Mettre à jour le premier numéro
                phones[0]['value'] = user_data['phoneNumber']
            else:
                # Ajouter un nouveau numéro
                phones = [{'type': 'work', 'primary': True, 'value': user_data['phoneNumber']}]
            
            update_body['phones'] = phones
        
        # Mettre à jour la fonction/titre
        if 'jobTitle' in user_data:
            organizations = current_user_info.get('organizations', [])
            if organizations:
                # Mettre à jour le premier organization
                organizations[0]['title'] = user_data['jobTitle']
            else:
                # Ajouter une nouvelle organization
                organizations = [{'title': user_data['jobTitle'], 'primary': True, 'type': 'work'}]
            
            update_body['organizations'] = organizations
        
        # Mettre à jour le département
        if 'department' in user_data:
            organizations = update_body.get('organizations', current_user_info.get('organizations', []))
            if organizations:
                organizations[0]['department'] = user_data['department']
            else:
                organizations = [{'department': user_data['department'], 'primary': True, 'type': 'work'}]
            
            update_body['organizations'] = organizations
        
        # Effectuer la mise à jour si des modifications ont été demandées
        if update_body:
            admin_service.users().update(userKey=user_email, body=update_body).execute()
            return True, "Informations de l'utilisateur mises à jour avec succès"
        else:
            return False, "Aucune information à mettre à jour"
            
    except Exception as e:
        logger.error(f"Erreur lors de la mise à jour des informations de l'utilisateur {user_email}: {e}")
        return False, f"Erreur lors de la mise à jour: {str(e)}"

# ----- ROUTES DE L'APPLICATION -----

@app.route('/')
def index():
    """
    Page d'accueil avec liste des utilisateurs.
    """
    try:
        # Récupérer les utilisateurs du domaine
        service = get_admin_service()
        if service:
            results = service.users().list(domain=DOMAIN, maxResults=100).execute()
            users = results.get('users', [])
            return render_template('index.html', users=users)
        else:
            flash("Configuration d'authentification incomplète. Veuillez configurer vos identifiants Google.")
            return render_template('index.html', users=[])
    except Exception as e:
        logger.error(f"Erreur dans la route index: {e}")
        flash(f"Erreur lors de la récupération des utilisateurs: {str(e)}")
        return render_template('index.html', users=[])

@app.route('/signature/<user_email>', methods=['GET', 'POST'])
def signature(user_email):
    """
    Page pour éditer et prévisualiser la signature d'un utilisateur.
    Supporte l'extraction et le stockage local des images.
    """
    # Vérifier si on a les credentials nécessaires
    credentials = get_credentials()
    if not credentials:
        flash("Erreur d'authentification. Veuillez vérifier votre configuration.")
        return redirect(url_for('index'))
    
    # Obtenir les services API
    admin_service = get_admin_service()
    if not admin_service:
        flash("Impossible de se connecter au service Admin Google. Vérifiez vos permissions.")
        return redirect(url_for('index'))
    
    # Récupérer les informations de l'utilisateur
    user_info = {}
    try:
        user_info = admin_service.users().get(userKey=user_email).execute()
    except Exception as e:
        logger.error(f"Erreur lors de la récupération des informations de l'utilisateur: {e}")
        flash(f"Erreur lors de la récupération des informations de l'utilisateur: {str(e)}")
    
    # Récupérer les adresses d'envoi de l'utilisateur
    send_as_list = get_user_send_as_addresses(user_email)
    
    # Récupérer l'adresse sélectionnée du paramètre ou utiliser celle de l'URL
    selected_email = request.args.get('selected_email', user_email)
    
    # Vérifier si l'email sélectionné est une adresse d'envoi valide
    is_valid_send_as = False
    current_signature = ""
    primary_email = user_info.get('primaryEmail', '')
    
    for send_as in send_as_list:
        if send_as['sendAsEmail'] == selected_email:
            is_valid_send_as = True
            current_signature = send_as.get('signature', '')
            break
    
    # Traitement du formulaire (POST)
    if request.method == 'POST':
        html_signature = request.form.get('signature', '')
        target_email = request.form.get('target_email', selected_email)
        
        # Préserver les chemins d'images existants
        html_signature = preserve_image_paths(current_signature, html_signature)
        
        # Traiter les nouvelles images (base64 ou externes)
        if '<img' in html_signature:
            html_signature = extract_and_save_images(html_signature, app.static_folder)
        
        # Vérifier si l'adresse cible est une adresse d'envoi valide
        target_is_valid = False
        for send_as in send_as_list:
            if send_as['sendAsEmail'] == target_email:
                target_is_valid = True
                break
        
        if target_is_valid:
            # Mettre à jour la signature
            success, message = update_signature(user_email, target_email, html_signature, request)
            
            if success:
                flash(message)
                # Rediriger vers la même adresse pour afficher les changements
                return redirect(url_for('signature', user_email=user_email, selected_email=target_email))
            else:
                for error in message:
                    flash(error)
        else:
            flash(f"L'adresse {target_email} n'est pas une adresse d'envoi valide pour cet utilisateur.")
        
        return redirect(url_for('signature', user_email=user_email, selected_email=selected_email))
    
    # Pour la méthode GET, extraire les images si nécessaire
    if current_signature and ('<img' in current_signature):
        try:
            # Extraire et sauvegarder les images localement
            current_signature = extract_and_save_images(
                current_signature,
                app.static_folder
            )
        except Exception as e:
            logger.error(f"Erreur lors de l'extraction des images: {e}")
            flash(f"Erreur lors de l'extraction des images: {str(e)}")
    
    # Vérifier si l'utilisateur fait partie d'un groupe
    user_groups = []
    if ENABLE_GROUPS:
        try:
            groups_response = admin_service.groups().list(userKey=user_email).execute()
            user_groups = groups_response.get('groups', [])
        except Exception as e:
            logger.warning(f"Impossible d'obtenir les groupes pour {user_email}: {e}")
            # Ignorer les erreurs de groupe, ce n'est pas crucial
    
    # Préparer les données du template
    signature_templates = [
        {
            "name": "Default",
            "html": f"""<table border="0" cellpadding="0" cellspacing="0" style="font-family:'Open Sans',Arial,sans-serif;color:#000000;font-size:12px;line-height:16px;border-collapse:collapse">
                <tr>
                    <td style="vertical-align:top;padding-right:40px;padding-left:40px;padding-bottom:0px;width:75px">
                        <table border="0" cellpadding="0" cellspacing="0" style="width:100%">
                            <tr>
                                <td align="center" style="padding-bottom:5px">
                                    <img alt="Scorimmo Logo" height="75" src="https://stk.scorimmo.com/IMG/logo-seul-avec-S-bleu.png" style="display:block;border:0" width="75">
                                </td>
                            </tr>
                             <tr>
                                <td align="center" style="padding-top:5px;padding-bottom:0px">
                                    <span style="font-size:14px;font-family:Arial,sans-serif">🏠</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td style="vertical-align:top;padding-bottom:0px">
                        <table border="0" cellpadding="0" cellspacing="0" style="font-family:'Open Sans',Arial,sans-serif;color:#000000;border-collapse:collapse">
                            <tr>
                                <td style="font-size:14px;line-height:20px;font-weight:400;padding-bottom:2px">{user_info.get('name', {}).get('fullName', '')}</td>
                            </tr>
                            <tr>
                                <td style="font-size:14px;line-height:20px;padding-bottom:2px">{user_info.get('organizations', [{}])[0].get('title', '') if user_info.get('organizations') else ''}</td>
                            </tr>
                            <tr>
                                <td style="font-size:14px;line-height:20px;padding-bottom:2px;padding-top:16px">{user_info.get('phones', [{}])[0].get('value', '') if user_info.get('phones') else ''}</td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;line-height:16px">
                                    <a href="https://scorimmo.com" style="color:#000000;text-decoration:none" target="_blank">scorimmo.com</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top:0px;border-top:1px solid #000000">
                         <!-- Ligne de séparation noire -->
                    </td>
                </tr>
            </table>"""
        },
        {
            "name": "Avec logo",
            "html": f"""<div style="font-family: Arial, sans-serif; font-size: 12px;">
                <table>
                    <tr>
                        <td style="padding-right: 15px; vertical-align: top;">
                            <img src="https://stk.scorimmo.com/IMG/logo-seul-avec-S-bleu.png" alt="Logo" width="100">
                        </td>
                        <td style="vertical-align: top;">
                            <p><strong>{user_info.get('name', {}).get('fullName', '')}</strong><br>
                            {user_info.get('organizations', [{}])[0].get('title', '') if user_info.get('organizations') else ''}<br>
                            {selected_email} | {user_info.get('phones', [{}])[0].get('value', '') if user_info.get('phones') else ''}</p>
                            <p>{DOMAIN}</p>
                        </td>
                    </tr>
                </table>
            </div>"""
        }
    ]
    
    return render_template('signature.html',
                          user_email=user_email,
                          user_info=user_info,
                          send_as_list=send_as_list,
                          current_signature=current_signature,
                          is_valid_send_as=is_valid_send_as,
                          primary_email=primary_email,
                          selected_email=selected_email,
                          user_groups=user_groups,
                          signature_templates=signature_templates)

@app.route('/user-info/<user_email>', methods=['GET', 'POST'])
def edit_user_info(user_email):
    """
    Page pour éditer les informations d'un utilisateur.
    """
    # Vérifier si on a les credentials nécessaires
    credentials = get_credentials()
    if not credentials:
        flash("Erreur d'authentification. Veuillez vérifier votre configuration.")
        return redirect(url_for('index'))
    
    # Obtenir les services API
    admin_service = get_admin_service()
    if not admin_service:
        flash("Impossible de se connecter au service Admin Google. Vérifiez vos permissions.")
        return redirect(url_for('index'))
    
    # Récupérer les informations de l'utilisateur
    user_info = {}
    try:
        user_info = admin_service.users().get(userKey=user_email).execute()
    except Exception as e:
        logger.error(f"Erreur lors de la récupération des informations de l'utilisateur: {e}")
        flash(f"Erreur lors de la récupération des informations de l'utilisateur: {str(e)}")
        return redirect(url_for('index'))
    
    # Traitement du formulaire (POST)
    if request.method == 'POST':
        # Récupérer les données du formulaire
        user_data = {
            'firstName': request.form.get('firstName', ''),
            'lastName': request.form.get('lastName', ''),
            'phoneNumber': request.form.get('phoneNumber', ''),
            'jobTitle': request.form.get('jobTitle', ''),
            'department': request.form.get('department', '')
        }
        
        # Filtrer les champs vides
        user_data = {k: v for k, v in user_data.items() if v}
        
        # Mettre à jour les informations de l'utilisateur
        success, message = update_user_info(user_email, user_data)
        
        if success:
            flash(message)
        else:
            flash(f"Erreur: {message}")
        
        # Rediriger vers la même page pour afficher les changements
        return redirect(url_for('edit_user_info', user_email=user_email))
    
    # Préparer les données pour le template
    name = user_info.get('name', {})
    organizations = user_info.get('organizations', [{}])
    phones = user_info.get('phones', [{}])
    
    # Extraire les valeurs actuelles
    first_name = name.get('givenName', '')
    last_name = name.get('familyName', '')
    job_title = organizations[0].get('title', '') if organizations else ''
    department = organizations[0].get('department', '') if organizations else ''
    phone_number = phones[0].get('value', '') if phones else ''
    
    return render_template('user_info.html',
                          user_email=user_email,
                          user_info=user_info,
                          first_name=first_name,
                          last_name=last_name,
                          job_title=job_title,
                          department=department,
                          phone_number=phone_number)

@app.route('/api/upload-image', methods=['POST'])
def upload_image():
    """
    API pour uploader des images pour l'éditeur de signature.
    """
    if 'image' not in request.files:
        return jsonify({'error': 'Aucune image fournie'}), 400
    
    file = request.files['image']
    if file.filename == '':
        return jsonify({'error': 'Nom de fichier vide'}), 400
    
    # Sauvegarder temporairement l'image
    upload_folder = os.path.join(app.static_folder, 'uploads')
    os.makedirs(upload_folder, exist_ok=True)
    file_path = os.path.join(upload_folder, file.filename)
    file.save(file_path)
    
    # Retourner l'URL pour accéder à l'image
    image_url = url_for('static', filename=f'uploads/{file.filename}')
    return jsonify({'url': image_url})

@app.route('/bulk-apply', methods=['POST'])
def bulk_apply():
    """
    Appliquer une signature à plusieurs utilisateurs.
    """
    data = request.json
    user_emails = data.get('users', [])
    signature = data.get('signature', '')
    replace_variables = data.get('replaceVariables', False)
    
    results = {'success': [], 'failed': []}
    
    for email in user_emails:
        try:
            # Si on doit remplacer les variables, récupérer les infos de l'utilisateur
            if replace_variables:
                # Récupérer les infos de l'utilisateur
                admin_service = get_admin_service()
                user_info = admin_service.users().get(userKey=email).execute()
                
                # Remplacer les variables dans la signature
                user_signature = signature
                user_signature = user_signature.replace('{NOM}', user_info.get('name', {}).get('fullName', ''))
                user_signature = user_signature.replace('{EMAIL}', email)
                
                # Remplacer les variables supplémentaires
                user_signature = user_signature.replace('{FONCTION}', 
                    user_info.get('organizations', [{}])[0].get('title', '') if user_info.get('organizations') else '')
                user_signature = user_signature.replace('{TELEPHONE}', 
                    user_info.get('phones', [{}])[0].get('value', '') if user_info.get('phones') else '')
                user_signature = user_signature.replace('{DEPARTEMENT}', 
                    user_info.get('organizations', [{}])[0].get('department', '') if user_info.get('organizations') else '')
            else:
                user_signature = signature
            
            # Traiter les images dans la signature
            if '<img' in user_signature:
                user_signature = extract_and_save_images(user_signature, app.static_folder)
            
            # Mettre à jour la signature
            success, message = update_signature(email, email, user_signature, request)
            
            if success:
                results['success'].append(email)
            else:
                results['failed'].append({'email': email, 'error': '; '.join(message)})
                
        except Exception as e:
            logger.error(f"Erreur lors de l'application en masse pour {email}: {e}")
            results['failed'].append({'email': email, 'error': str(e)})
    
    return jsonify(results)

@app.route('/api/user-info', methods=['POST'])
def api_user_info():
    """
    API pour mettre à jour les informations d'un utilisateur.
    Utile pour les mises à jour AJAX sans recharger la page.
    """
    data = request.json
    user_email = data.get('email')
    user_data = data.get('data', {})
    
    if not user_email:
        return jsonify({'success': False, 'message': 'Email utilisateur manquant'}), 400
    
    success, message = update_user_info(user_email, user_data)
    
    return jsonify({
        'success': success,
        'message': message
    })

@app.route('/bulk-update-users', methods=['GET', 'POST'])
def bulk_update_users():
    """
    Page pour mettre à jour les informations de plusieurs utilisateurs en masse.
    """
    if request.method == 'POST':
        data = request.json
        users = data.get('users', [])
        user_data = data.get('data', {})
        
        results = {'success': [], 'failed': []}
        
        for email in users:
            success, message = update_user_info(email, user_data)
            if success:
                results['success'].append(email)
            else:
                results['failed'].append({'email': email, 'error': message})
        
        return jsonify(results)
    
    # Pour la méthode GET, afficher la page de mise à jour en masse
    try:
        # Récupérer les utilisateurs du domaine
        service = get_admin_service()
        if service:
            results = service.users().list(domain=DOMAIN, maxResults=100).execute()
            users = results.get('users', [])
            return render_template('bulk_update_users.html', users=users)
        else:
            flash("Configuration d'authentification incomplète. Veuillez configurer vos identifiants Google.")
            return render_template('bulk_update_users.html', users=[])
    except Exception as e:
        logger.error(f"Erreur dans la route bulk_update_users: {e}")
        flash(f"Erreur lors de la récupération des utilisateurs: {str(e)}")
        return render_template('bulk_update_users.html', users=[])

# ----- INITIALISATION DE L'APPLICATION -----

# Créer les dossiers nécessaires avant le démarrage
def create_folders():
    """
    Crée les dossiers nécessaires au démarrage de l'application.
    """
    try:
        os.makedirs(os.path.join(app.root_path, 'static'), exist_ok=True)
        os.makedirs(os.path.join(app.root_path, 'static', 'uploads'), exist_ok=True)
        
        # Vérifier la configuration
        if not os.path.exists(SERVICE_ACCOUNT_FILE):
            logger.warning(f"Le fichier de compte de service '{SERVICE_ACCOUNT_FILE}' n'existe pas!")
            
        logger.info(f"Application démarrée pour le domaine {DOMAIN}")
    except Exception as e:
        logger.error(f"Erreur lors de l'initialisation: {e}")

# Appelez simplement la fonction avant de démarrer l'application
create_folders()

# ----- POINT D'ENTRÉE -----

if __name__ == '__main__':
    # Configuration pour le développement
    app.run(debug=True, host='0.0.0.0', port=5000)