# Perdigueiro — Treinamento e Avaliação do Modelo de Reidentificação de Cães

Este diretório contém o notebook desenvolvido para o treinamento, a validação e a avaliação de um modelo de inteligência artificial destinado à **reidentificação individual de cães por similaridade visual**.

O experimento integra o Trabalho de Conclusão de Curso em Engenharia de Computação da Universidade Tecnológica Federal do Paraná (UTFPR).

## 1. Objetivo

O objetivo é desenvolver um modelo capaz de extrair características visuais discriminativas de fotografias de cães e utilizá-las para identificar possíveis correspondências entre animais.

Diferentemente de sistemas de classificação de raças, a proposta busca reconhecer **indivíduos específicos**, considerando características visuais presentes em diferentes fotografias do mesmo animal.

O modelo foi desenvolvido para integração com a plataforma web **Perdigueiro**, voltada ao auxílio na localização de cães desaparecidos.

## 2. Arquitetura do modelo

A solução utiliza uma rede neural convolucional baseada na arquitetura **VGG16**, com pesos pré-treinados no ImageNet e aplicação de *transfer learning*.

A rede foi adaptada para gerar vetores de características (*embeddings*) de 128 dimensões, posteriormente normalizados pela norma L2.

O treinamento utiliza **Triplet Loss**, buscando aproximar os embeddings de fotografias do mesmo cão e afastar os embeddings de animais diferentes.

### Principais componentes

- **Backbone:** VGG16.
- **Inicialização:** pesos pré-treinados no ImageNet.
- **Representação:** embeddings de 128 dimensões.
- **Normalização:** L2.
- **Função de perda:** Triplet Loss.
- **Mineração de trincas:** semi-hard negative mining.
- **Otimizador:** Adam.
- **Estratégia:** treinamento em duas fases, com ajuste fino (*fine-tuning*).

## 3. Organização dos dados

O conjunto de imagens é organizado por identidade, de modo que cada cão represente uma classe individual.

O protocolo experimental contempla:

- **70% das identidades:** conjunto de desenvolvimento.
- **30% das identidades:** conjunto independente de teste.
- **Validação cruzada:** cinco folds no conjunto de desenvolvimento.

A separação por identidade busca impedir que fotografias do mesmo cão sejam distribuídas entre os conjuntos de desenvolvimento e teste.

Essa estratégia permite avaliar a capacidade de generalização do modelo para animais não utilizados durante o treinamento.

## 4. Hiperparâmetros

| Parâmetro | Configuração |
|---|---|
| Arquitetura | VGG16 |
| Dimensão dos embeddings | 128 |
| Otimizador | Adam |
| Taxa de aprendizado inicial | 0,0001 |
| Taxa de aprendizado no fine-tuning | 0,00001 |
| Margem da Triplet Loss | 0,2 |
| Identidades por lote (P) | 8 |
| Imagens por identidade (K) | 4 |
| Épocas por fase | 10 |
| Validação cruzada | 5 folds |
| Divisão desenvolvimento/teste | 70% / 30% |

Essas configurações correspondem ao protocolo experimental documentado no TCC.

## 5. Avaliação

O notebook contempla procedimentos para avaliar a capacidade de recuperação de imagens correspondentes a uma identidade consultada.

As métricas utilizadas incluem:

- **Rank-1:** proporção de consultas em que o primeiro candidato corresponde à identidade correta.
- **Rank-5 e Rank-10:** proporção de consultas em que a identidade correta aparece entre os cinco ou dez primeiros candidatos.
- **mAP (Mean Average Precision):** medida da qualidade do ranqueamento.
- **ROC-AUC:** avaliação da capacidade de discriminação entre pares positivos e negativos.

Também são realizados experimentos considerando diferentes quantidades de fotografias de referência por animal, permitindo analisar o impacto da utilização de múltiplas imagens na recuperação de identidades.

## 6. Notebook

**Arquivo:** `Perdigueiro_VGG16_Triplet_KFold_GitHub_v2.ipynb`

O notebook reúne os procedimentos de preparação dos dados, construção do modelo, treinamento, validação cruzada, avaliação e exportação.

O código foi desenvolvido para execução no **Google Colab**, com suporte a aceleração por GPU.

## 7. Como executar

1. Abra o notebook no Google Colab.
2. Configure o ambiente de execução com GPU, quando disponível.
3. Disponibilize o conjunto de imagens no Google Drive.
4. Ajuste os caminhos de acesso aos datasets e aos diretórios de saída.
5. Instale ou configure as dependências necessárias.
6. Execute as células seguindo as orientações e dependências descritas no notebook.

**Observação:** a reprodução integral depende da disponibilidade dos datasets, da configuração do ambiente e, em determinados procedimentos, dos checkpoints utilizados durante os experimentos.

O notebook disponibilizado constitui o registro do desenvolvimento experimental. Sua execução integral em uma sessão limpa do Google Colab ainda não foi validada.

## 8. Integração com a plataforma Perdigueiro

O treinamento é uma das etapas da arquitetura geral do projeto.

Após o desenvolvimento, o modelo de reidentificação é utilizado por um serviço de inferência responsável por processar imagens e gerar embeddings.

A aplicação WordPress utiliza esse serviço para apoiar a busca de possíveis correspondências entre cães cadastrados.

O fluxo geral é:

**Fotografia → Pré-processamento → VGG16 → Embedding normalizado → Comparação por similaridade → Ranqueamento de candidatos**

A plataforma também utiliza informações cadastrais dos animais para auxiliar na seleção de possíveis correspondências.

## 9. Considerações sobre os resultados

O objetivo dos experimentos é avaliar a viabilidade técnica da reidentificação individual de cães em condições de variação visual.

As métricas e os gráficos produzidos durante a avaliação são discutidos no Trabalho de Conclusão de Curso.

Os resultados devem ser interpretados considerando as características do conjunto de dados, o protocolo de separação por identidade e as condições experimentais utilizadas.

## 10. Autor e finalidade

**William de Souza Colodro**

Engenharia de Computação — Universidade Tecnológica Federal do Paraná (UTFPR).

Projeto desenvolvido para fins acadêmicos, como parte do Trabalho de Conclusão de Curso.

**Perdigueiro — Inteligência artificial aplicada à reidentificação de cães.**
