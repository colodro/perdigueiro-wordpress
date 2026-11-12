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

O treinamento utiliza **Triplet Loss**, buscando aproxim
