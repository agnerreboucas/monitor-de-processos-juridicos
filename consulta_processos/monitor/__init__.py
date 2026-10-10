"""Monitor de publicações: acompanha OABs e processos, guarda o que é novo e mostra
prazos, audiências e alertas num painel.

    consulta-processos monitor adicionar-oab 123456 MG --nome "Dra. Fulana"
    consulta-processos monitor adicionar-processo 1000254-20.2025.8.13.0079
    consulta-processos monitor executar        # a rotina diária (cron)
    consulta-processos monitor painel          # a tela
"""
from .banco import Banco, ErroDeCadastro
from .classificar import CATEGORIAS, classificar, extrair_audiencia, sugerir_prazo
from .motor import Resultado, executar

__all__ = ["CATEGORIAS", "Banco", "ErroDeCadastro", "Resultado", "classificar",
           "executar", "extrair_audiencia", "sugerir_prazo"]
