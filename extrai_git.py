import sys
import subprocess
import csv
from datetime import datetime
from collections import defaultdict

def parse_args(args):
    """Lê os parâmetros passados no formato chave=valor."""
    params = {}
    for arg in args[1:]:
        if '=' in arg:
            key, value = arg.split('=', 1)
            params[key.lower()] = value.strip()
    return params

def format_date(date_str):
    """Converte datas do formato DD/MM/YYYY para YYYY-MM-DD (aceito pelo git log)."""
    try:
        return datetime.strptime(date_str, "%d/%m/%Y").strftime("%Y-%m-%d")
    except ValueError:
        print(f"Erro: Formato de data inválido ({date_str}). Use DD/MM/YYYY.")
        sys.exit(1)

def get_git_commits(branch, since_date, until_date):
    """Executa o git log para obter os commits com arquivos alterados."""
    delimiter = "---COMMIT_SPLIT---"
    git_format = f"{delimiter}%n%H%n%an%n%s"
    
    cmd = [
        "git", "log", branch,
        f"--since={since_date} 00:00:00",
        f"--until={until_date} 23:59:59",
        f"--pretty=format:{git_format}",
        "--name-only"
    ]
    
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, check=True, encoding='utf-8', errors='replace')
    except subprocess.CalledProcessError as e:
        print(f"Erro ao executar o comando git: {e.stderr}")
        sys.exit(1)
        
    commits_raw = result.stdout.split(delimiter)
    commits_data = []

    for block in commits_raw:
        lines = [line.strip() for line in block.strip().split('\n') if line.strip()]
        if len(lines) >= 3:
            commit_hash = lines[0]
            author = lines[1]
            subject = lines[2]
            files = lines[3:]
            
            commits_data.append({
                'hash': commit_hash,
                'author': author,
                'subject': subject,
                'files': files
            })
            
    return commits_data

def split_file_path(file_path):
    """Separa o caminho do arquivo em (pasta, nome_do_arquivo)."""
    if '/' in file_path:
        folder, filename = file_path.rsplit('/', 1)
        return folder + '/', filename
    return './', file_path

def process_data(commits, filter_author=None, filter_folder=None, filter_ext=None, layout=1):
    """Filtra os dados e os estrutura conforme o layout desejado (1, 2 ou 3)."""
    rows = []
    
    # Padroniza a extensão para garantir comparação correta
    if filter_ext:
        filter_ext = filter_ext.lower()
        if not filter_ext.startswith('.'):
            filter_ext = '.' + filter_ext

    if layout == 1:
        # Layout 1: pasta, autores
        folder_authors = defaultdict(set)
        
        for commit in commits:
            author = commit['author']
            if filter_author and filter_author.lower() not in author.lower():
                continue
                
            for file_path in commit['files']:
                folder, filename = split_file_path(file_path)
                
                if filter_folder and not folder.startswith(filter_folder):
                    continue
                if filter_ext and not filename.lower().endswith(filter_ext):
                    continue
                    
                folder_authors[folder].add(author)
                
        for folder in sorted(folder_authors.keys()):
            authors_str = ", ".join(sorted(folder_authors[folder]))
            rows.append({'pasta': folder, 'autores': authors_str})
            
    elif layout == 2:
        # Layout 2: pasta, "titulo commit", autor
        seen_entries = set()
        
        for commit in commits:
            author = commit['author']
            subject = commit['subject']
            
            if filter_author and filter_author.lower() not in author.lower():
                continue
                
            for file_path in commit['files']:
                folder, filename = split_file_path(file_path)
                
                if filter_folder and not folder.startswith(filter_folder):
                    continue
                if filter_ext and not filename.lower().endswith(filter_ext):
                    continue
                    
                entry_key = (folder, subject, author)
                if entry_key not in seen_entries:
                    seen_entries.add(entry_key)
                    rows.append({
                        'pasta': folder,
                        'titulo_commit': f'"{subject}"',
                        'autor': author
                    })
                    
        rows.sort(key=lambda x: (x['pasta'], x['autor']))

    elif layout == 3:
        # Layout 3: pasta, arquivo, "titulo commit", autor
        seen_entries = set()
        
        for commit in commits:
            author = commit['author']
            subject = commit['subject']
            
            if filter_author and filter_author.lower() not in author.lower():
                continue
                
            for file_path in commit['files']:
                folder, filename = split_file_path(file_path)
                
                if filter_folder and not folder.startswith(filter_folder):
                    continue
                if filter_ext and not filename.lower().endswith(filter_ext):
                    continue
                    
                entry_key = (folder, filename, subject, author)
                if entry_key not in seen_entries:
                    seen_entries.add(entry_key)
                    rows.append({
                        'pasta': folder,
                        'arquivo': filename,
                        'titulo_commit': f'"{subject}"',
                        'autor': author
                    })
                    
        rows.sort(key=lambda x: (x['pasta'], x['arquivo'], x['autor']))

    return rows

def print_output(data, layout):
    """Exibe o resultado no terminal com alinhamento proporcional."""
    if not data:
        print("Nenhum dado encontrado para os filtros informados.")
        return

    if layout == 1:
        max_folder = max(len(row['pasta']) for row in data) + 4
        for row in data:
            print(f"{row['pasta'].ljust(max_folder)}{row['autores']}")
            
    elif layout == 2:
        max_folder = max(len(row['pasta']) for row in data) + 4
        max_title = max(len(row['titulo_commit']) for row in data) + 4
        for row in data:
            print(f"{row['pasta'].ljust(max_folder)}{row['titulo_commit'].ljust(max_title)}{row['autor']}")
            
    elif layout == 3:
        max_folder = max(len(row['pasta']) for row in data) + 4
        max_file = max(len(row['arquivo']) for row in data) + 4
        max_title = max(len(row['titulo_commit']) for row in data) + 4
        for row in data:
            print(f"{row['pasta'].ljust(max_folder)}{row['arquivo'].ljust(max_file)}{row['titulo_commit'].ljust(max_title)}{row['autor']}")

def export_csv(data, layout, filename="saida_git.csv"):
    """Exporta os dados filtrados para um arquivo CSV delimitado por ';'."""
    if not data:
        return
        
    fieldnames = list(data[0].keys())
    
    with open(filename, mode='w', newline='', encoding='utf-8-sig') as file:
        writer = csv.DictWriter(file, fieldnames=fieldnames, delimiter=';')
        writer.writeheader()
        writer.writerows(data)
        
    print(f"\nArquivo CSV gerado com sucesso: {filename}")

def main():
    params = parse_args(sys.argv)
    
    branch = params.get('local')
    dates = params.get('data')
    layout = params.get('layout')
    
    if not branch or not dates or not layout:
        print("Uso incorreto. Parâmetros obrigatórios faltando!")
        print("Exemplo: python extrai_git.py local=origin/dev data=01/08/2026-31/10/2026 layout=3 autor=\"Leonardo Meireles\" ext=php [folter=caminho/] [csv=y]")
        sys.exit(1)
        
    try:
        start_date_str, end_date_str = dates.split('-')
    except ValueError:
        print("Erro: O parâmetro 'data' deve estar no formato DD/MM/YYYY-DD/MM/YYYY.")
        sys.exit(1)

    since_date = format_date(start_date_str)
    until_date = format_date(end_date_str)
    layout_type = int(layout)
    
    if layout_type not in (1, 2, 3):
        print("Erro: O layout informado deve ser 1, 2 ou 3.")
        sys.exit(1)

    filter_author = params.get('autor')
    filter_folder = params.get('folter') or params.get('folder')
    filter_ext = params.get('ext')
    generate_csv = params.get('csv', '').lower() == 'y'

    commits = get_git_commits(branch, since_date, until_date)
    result_data = process_data(commits, filter_author, filter_folder, filter_ext, layout_type)
    
    print_output(result_data, layout_type)
    
    if generate_csv:
        export_csv(result_data, layout_type)

if __name__ == "__main__":
    main()
    