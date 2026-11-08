# -*- coding: utf-8 -*-
from pathlib import Path
from fpdf import FPDF

font = Path(r"C:\Windows\Fonts\arial.ttf")
font_b = Path(r"C:\Windows\Fonts\arialbd.ttf")
out = Path(r"C:\Users\PC\Desktop\Peto\PRM-Google-Drive-OAuth-Kurulum.pdf")
# also copy next to extension
out2 = Path(r"C:\Users\PC\Desktop\Peto\extensions\prmgoogledrive\PRM-Google-Drive-OAuth-Kurulum.pdf")

pdf = FPDF(format="A4")
pdf.set_auto_page_break(auto=True, margin=18)
pdf.add_page()
pdf.set_left_margin(16)
pdf.set_right_margin(16)
pdf.set_x(16)
pdf.add_font("ArialTR", "", str(font))
pdf.add_font("ArialTR", "B", str(font_b))


def h1(t: str) -> None:
    pdf.set_x(16)
    pdf.set_font("ArialTR", "B", 18)
    pdf.multi_cell(0, 10, t)
    pdf.ln(2)


def h2(t: str) -> None:
    pdf.set_x(16)
    pdf.set_font("ArialTR", "B", 13)
    pdf.ln(3)
    pdf.multi_cell(0, 8, t)
    pdf.ln(1)


def body(t: str) -> None:
    pdf.set_x(16)
    pdf.set_font("ArialTR", "", 11)
    pdf.multi_cell(0, 6.5, t)
    pdf.ln(1)


def bullet(t: str) -> None:
    pdf.set_x(16)
    pdf.set_font("ArialTR", "", 11)
    pdf.multi_cell(0, 6.5, f"- {t}")


def code(t: str) -> None:
    pdf.set_x(16)
    pdf.set_font("ArialTR", "", 10)
    pdf.set_fill_color(240, 240, 240)
    pdf.multi_cell(0, 7, t, fill=True)
    pdf.ln(2)


h1("PRM Google Drive Backup")
pdf.set_font("ArialTR", "B", 12)
pdf.multi_cell(0, 7, "Google OAuth Client ID / Secret Kurulum Rehberi")
pdf.ln(2)
body(
    "Kısaca: Google Cloud'da bir OAuth istemcisi oluşturuyorsun; oradan "
    "Client ID + Client Secret kopyalıyorsun. Eklentiyi kuran kullanıcılar "
    "Cloud Console açmaz; sadece Google ile bağlan der."
)

h2("1) Projeyi aç")
bullet("Aç: https://console.cloud.google.com/apis/credentials")
bullet("Google hesabınla gir")
bullet("Üstte proje yoksa: Select a project → New Project")
bullet("İsim: PRM Drive")
bullet("Create")

h2("2) Drive API'yi aç")
bullet("Aç: https://console.cloud.google.com/apis/library/drive.googleapis.com")
bullet("Enable")

h2("3) OAuth consent (zorunlu ekran)")
bullet("Aç: https://console.cloud.google.com/apis/credentials/consent")
bullet("User Type: External → Create")
bullet("App name: PRM Backup")
bullet("User support email: kendi mailin")
bullet("Developer contact: kendi mailin")
bullet("Save and Continue")
bullet("Scopes ekranında Save and Continue (şimdilik ek scope şart değil)")
bullet("Test users: Add users → kendi Gmail'ini ekle → Continue → Back to Dashboard")
bullet("Yayın durumu Testing kalsın (yeter)")

h2("4) Client ID / Secret al (asıl iş)")
bullet("Aç: https://console.cloud.google.com/apis/credentials")
bullet("+ Create Credentials → OAuth client ID")
bullet("Application type: Web application")
bullet("Name: PRM Panel")
bullet("Authorized redirect URIs → Add URI, tam olarak şunu yapıştır:")
code("http://localhost:8080/extensions/prmgoogledrive/oauth/callback")
bullet("Create")
bullet("Çıkan pencerede Client ID ve Client secret değerlerini kopyala")

h2("5) Panel'e koy")
body("docker-compose.yml içinde:")
code(
    'PRM_GDRIVE_CLIENT_ID: "buraya-client-id"\n'
    'PRM_GDRIVE_CLIENT_SECRET: "buraya-client-secret"'
)
body("Sonra:")
code("docker compose up -d panel")
body("Admin → Extensions → PRM Google Drive Backup → Google ile bağlan / yetki ver")

h2("Notlar")
bullet("Redirect URI birebir aynı olmalı (http/https, port, path).")
bullet("Testing modunda sadece eklediğin test kullanıcıları bağlanabilir.")
bullet("Secret'ı herkese açık paylaşma; sızdıysa Google Cloud'da rotate et.")
bullet("Canlı domain kullanıyorsan redirect URI'yi o domain ile de ekle.")

pdf.set_font("ArialTR", "", 9)
pdf.ln(6)
pdf.multi_cell(0, 5, "PRM / Peto — Google Drive Backup OAuth kurulum notu")

pdf.output(str(out))
pdf.output(str(out2))
print(out)
print("bytes", out.stat().st_size)
